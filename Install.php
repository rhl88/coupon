<?php

namespace App\Apps\CmsproCoupon;

use App\Models\ConfigGroup;
use App\Models\ConfigItem;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 优惠券应用 · 安装 / 卸载 / 升级脚本
 *
 * ┌ install()   安装：迁移兜底 → 配置三向同步 → 清理缓存
 * ├ upgrade()   升级：迁移兜底（增量迁移自动补执行）→ 配置三向同步 → 清理缓存
 * └ uninstall() 卸载：清理配置 → 回滚迁移（删表）→ 清理缓存
 *
 * 关键规范：
 * - 所有动作必须幂等，重复执行结果一致
 * - 迁移兜底：系统不保证 artisan migrate 覆盖应用目录，这里手工 require + up()
 *   并写 migrations 表去重，避免 php artisan migrate 重复执行
 * - 配置三向同步：以 manifest.json 为唯一基准
 *     新增项 → 写 value；已有项 → 只更新结构保留用户 value；已删项 → 清理残留
 * - 卸载通过迁移 down() 删表，禁止在 uninstall() 中直接 Schema::dropIfExists()
 * - 禁止在 install() 中创建 public/apps/{appId}/ 目录（会阻断 Assets 资源发布）
 */
class Install
{
    /**
     * 应用 ID（与 manifest.json 的 id 一致）
     */
    private const APP_ID = 'cmspro.coupon';

    /**
     * 日志前缀（便于在 laravel.log 中检索本应用安装过程）
     */
    private const LOG_TAG = '[cmspro.coupon][install] ';

    // ══════════════════════════════════════════════════════════════
    // 一、生命周期入口
    // ══════════════════════════════════════════════════════════════

    /**
     * 安装应用
     *
     * 顺序有依赖：迁移必须先于配置同步（config 表由框架迁移提供，
     * 此处仅确保应用业务表已就绪，避免后续功能首次访问时表缺失）。
     */
    public function install(): void
    {
        $this->runMigrations();
        $this->seedDefaultConfigs();
        $this->clearAppCache();

        Log::info(self::LOG_TAG . '安装完成');
    }

    /**
     * 卸载应用
     *
     * 顺序与安装相反：先清配置，再回滚表结构，最后清缓存。
     */
    public function uninstall(): void
    {
        $this->removeConfigs();
        $this->rollbackMigrations();
        $this->clearAppCache();

        Log::info(self::LOG_TAG . '卸载完成');
    }

    /**
     * 升级应用
     *
     * 增量迁移由 runMigrations() 自动识别执行；
     * manifest.json 新增的配置项由 seedDefaultConfigs() 补写，
     * 已存在的配置项保留用户在后台设置页修改过的值。
     *
     * @param string $fromVersion 升级前版本号
     * @param string $toVersion   升级后版本号
     */
    public function upgrade(string $fromVersion, string $toVersion): void
    {
        $this->runMigrations();
        $this->seedDefaultConfigs();
        $this->clearAppCache();

        Log::info(self::LOG_TAG . "升级完成：{$fromVersion} → {$toVersion}");
    }

    // ══════════════════════════════════════════════════════════════
    // 二、数据库迁移（兜底机制）
    // ══════════════════════════════════════════════════════════════

    /**
     * 执行本应用迁移文件
     *
     * 幂等保障：执行前查 migrations 表，已有记录直接跳过；
     * 执行成功后写入记录（batch 取当前最大值 +1）。
     */
    protected function runMigrations(): void
    {
        $files = glob(__DIR__ . '/Migrations/*.php') ?: [];
        sort($files); // 按文件名（时间戳）升序，保证建表先于改表

        foreach ($files as $file) {
            $migrationName = pathinfo($file, PATHINFO_FILENAME);

            if ($this->migrationRecordExists($migrationName)) {
                continue;
            }

            $migration = require $file;
            if (! is_object($migration) || ! method_exists($migration, 'up')) {
                continue;
            }

            try {
                $migration->up();
                $this->writeMigrationRecord($migrationName);
            } catch (\Throwable $e) {
                Log::error(self::LOG_TAG . '迁移执行失败', [
                    'file' => basename($file),
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * 回滚本应用迁移文件
     *
     * 逆序执行，保证「先删从表再删主表」；
     * 回滚成功后删除 migrations 记录，使下次安装可重新执行。
     */
    protected function rollbackMigrations(): void
    {
        $files = glob(__DIR__ . '/Migrations/*.php') ?: [];
        sort($files);

        foreach (array_reverse($files) as $file) {
            $migrationName = pathinfo($file, PATHINFO_FILENAME);

            if (! $this->migrationRecordExists($migrationName)) {
                continue;
            }

            $migration = require $file;
            if (! is_object($migration) || ! method_exists($migration, 'down')) {
                continue;
            }

            try {
                $migration->down();

                DB::table('migrations')->where('migration', $migrationName)->delete();
            } catch (\Throwable $e) {
                Log::error(self::LOG_TAG . '迁移回滚失败', [
                    'file' => basename($file),
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * migrations 表是否已有该迁移记录（表不存在时视为无记录）
     */
    protected function migrationRecordExists(string $migrationName): bool
    {
        try {
            return DB::table('migrations')->where('migration', $migrationName)->exists();
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * 写入 migrations 表记录（缺失会导致 artisan migrate 重复执行）
     */
    protected function writeMigrationRecord(string $migrationName): void
    {
        DB::table('migrations')->insert([
            'migration' => $migrationName,
            'batch' => (int) DB::table('migrations')->max('batch') + 1,
        ]);
    }

    // ══════════════════════════════════════════════════════════════
    // 三、配置项三向同步
    // ══════════════════════════════════════════════════════════════

    /**
     * 以 manifest.json 为基准同步配置组与配置项
     *
     * 三向同步语义：
     * - manifest 有 + DB 无 → 新增（写入 manifest 中的 value 默认值）
     * - manifest 有 + DB 有 → 更新结构（group_id/name/type/options/tips/sort/status），保留 value
     * - manifest 无 + DB 有 → 删除（防止历史配置残留导致设置页出现幽灵字段）
     */
    protected function seedDefaultConfigs(): void
    {
        $manifest = $this->readManifest();

        if ($manifest === null) {
            return;
        }

        $manifestCodes = [];

        foreach (($manifest['config_groups'] ?? []) as $groupIndex => $groupDef) {
            $group = ConfigGroup::updateOrCreate(
                ['code' => $this->buildGroupCode((string) $groupDef['name'])],
                [
                    'name' => (string) $groupDef['title'],
                    'app_id' => self::APP_ID,
                    'sort' => (int) $groupIndex,
                    'status' => 1,
                ]
            );

            foreach (($groupDef['items'] ?? []) as $index => $item) {
                $code = $this->buildItemCode((string) $item['name']);
                $manifestCodes[] = $code;

                $this->upsertConfigItem((int) $group->id, $item, $code, (int) $index);
            }
        }

        $this->cleanupOrphanItems($manifestCodes);
        $this->cleanupOrphanGroups();
    }

    /**
     * 创建或更新单个配置项
     *
     * @param int                 $groupId 所属配置组 ID（必须是真实 ID，写 0 会触发唯一索引冲突）
     * @param array<string,mixed> $item    manifest 中的配置项定义
     * @param string              $code    带应用前缀的完整 code
     * @param int                 $sort    排序值（取 manifest 中的数组下标）
     */
    protected function upsertConfigItem(int $groupId, array $item, string $code, int $sort): void
    {
        // 结构字段（含 select 类型的 options；ConfigItem::$casts 已将 options 声明为 array，
        // 直接传数组即可，不可手工 json_encode，否则会双重编码）
        $structure = [
            'group_id' => $groupId,
            'name' => (string) $item['title'],
            'type' => (string) ($item['type'] ?? 'text'),
            'options' => $item['options'] ?? null,
            'tips' => (string) ($item['tips'] ?? ''),
            'sort' => $sort,
            'status' => 1,
        ];

        $existing = ConfigItem::where('code', $code)->first();

        if ($existing !== null) {
            // 已存在：只同步结构，绝不覆盖用户在后台设置页保存的 value
            $existing->update($structure);

            return;
        }

        ConfigItem::create($structure + [
            'code' => $code,
            'value' => (string) ($item['value'] ?? ''),
        ]);
    }

    /**
     * 清理 manifest 中已移除的配置项
     *
     * @param array<int,string> $manifestCodes manifest 中当前保留的全部配置项 code
     */
    protected function cleanupOrphanItems(array $manifestCodes): void
    {
        ConfigItem::where('code', 'like', 'app_' . $this->normalizedAppId() . '_%')
            ->whereNotIn('code', $manifestCodes)
            ->delete();
    }

    /**
     * 清理无配置项的空配置组
     */
    protected function cleanupOrphanGroups(): void
    {
        ConfigGroup::where('app_id', self::APP_ID)
            ->whereDoesntHave('items')
            ->delete();
    }

    /**
     * 卸载时全量清理本应用配置
     *
     * 先删配置项再删配置组，避免出现无主配置项。
     */
    protected function removeConfigs(): void
    {
        $groupIds = ConfigGroup::where('app_id', self::APP_ID)->pluck('id')->all();

        if (! empty($groupIds)) {
            ConfigItem::whereIn('group_id', $groupIds)->delete();
        }

        // 兜底：按 code 前缀再清一次，防止历史数据 group_id 丢失
        ConfigItem::where('code', 'like', 'app_' . $this->normalizedAppId() . '_%')->delete();

        ConfigGroup::where('app_id', self::APP_ID)->delete();
    }

    // ══════════════════════════════════════════════════════════════
    // 四、辅助方法
    // ══════════════════════════════════════════════════════════════

    /**
     * 读取并解析 manifest.json
     *
     * @return array<string,mixed>|null 解析失败返回 null（不阻断安装流程）
     */
    protected function readManifest(): ?array
    {
        $path = __DIR__ . '/manifest.json';

        if (! file_exists($path)) {
            Log::error(self::LOG_TAG . 'manifest.json 不存在', ['path' => $path]);

            return null;
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        if (! is_array($decoded)) {
            Log::error(self::LOG_TAG . 'manifest.json 解析失败', ['path' => $path]);

            return null;
        }

        return $decoded;
    }

    /**
     * 清理本应用的运行时缓存
     *
     * 配置缓存键由 helpers.php 中的 cmspro_coupon_config() 写入，
     * 安装/卸载/升级后必须清理，避免读到过期值。
     */
    protected function clearAppCache(): void
    {
        try {
            if (function_exists('cmspro_coupon_forget_config_cache')) {
                cmspro_coupon_forget_config_cache();
            }

            Cache::forget('cmspro_coupon:expire:last_run');
        } catch (\Throwable $e) {
            Log::warning(self::LOG_TAG . '清理缓存失败', ['error' => $e->getMessage()]);
        }
    }

    /**
     * 应用 ID 的下划线形式（点号替换为下划线）
     *
     * @return string 如 cmspro_coupon
     */
    protected function normalizedAppId(): string
    {
        return str_replace('.', '_', self::APP_ID);
    }

    /**
     * 构建配置组 code
     *
     * @param string $groupName manifest 中配置组的 name
     * @return string 如 app_cmspro_coupon_basic
     */
    protected function buildGroupCode(string $groupName): string
    {
        return 'app_' . $this->normalizedAppId() . '_' . $groupName;
    }

    /**
     * 构建配置项 code
     *
     * @param string $itemName manifest 中配置项的 name
     * @return string 如 app_cmspro_coupon_receive_enabled
     */
    protected function buildItemCode(string $itemName): string
    {
        return 'app_' . $this->normalizedAppId() . '_' . $itemName;
    }
}
