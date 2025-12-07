Use Case #2

Prompt:
Как работает система ролей и прав в этом проекте?

Ответ:


Изучаю систему ролей и прав: конфигурацию, сервисы и модели.


[4 tools called]


Изучаю модели и сервисы для понимания архитектуры:


[5 tools called]


Изучаю дополнительные системы прав и их использование:


[5 tools called]


Изучаю миграции и примеры использования в роутах:


[6 tools called]


Проверяю конфигурацию полей метрик для понимания связи прав с полями:


[2 tools called]


## Архитектура системы ролей и прав

### 1. Базовая система (Spatie Permission)

Проект использует пакет **Spatie Laravel Permission** для базовой системы ролей и прав.

**Структура БД:**
- `permissions` — права
- `roles` — роли
- `model_has_permissions` — связь пользователей с правами
- `model_has_roles` — связь пользователей с ролями
- `role_has_permissions` — связь ролей с правами

**Модель User:**
```22:24:app/Models/User.php
class User extends Authenticatable implements JWTSubject
{
    use HasPermissions;
```

Модель использует трейт `HasPermissions` из Spatie, что дает методы:
- `$user->can('permission name')` — проверка права
- `$user->hasRole('role name')` — проверка роли
- `$user->syncPermissions()` — синхронизация прав

### 2. Дополнительные системы прав

#### A. RolePermissionConfig — конфигурация прав ролей

Таблица `role_permission_configs` связывает роли с правами и типом доступа:

```11:18:app/Services/RolePermissionConfig/Models/RolePermissionConfig.php
    public const PERMISSION_TYPE_VIEW = 1;
    public const PERMISSION_TYPE_UPDATE = 2;

    protected $fillable = [
        'role_id',
        'permission_id',
        'permission_type_id',
    ];
```

Типы:
- `PERMISSION_TYPE_VIEW = 1` — просмотр
- `PERMISSION_TYPE_UPDATE = 2` — обновление

#### B. UserItemTablePermission — права на просмотр полей

Таблица `user_item_table_permissions` задает, какие поля пользователь может видеть:

```9:16:app/Services/UserItemTablePermission/Models/UserItemTablePermission.php
class UserItemTablePermission extends Model
{
    protected $fillable = ['user_id', 'permission_id'];

    public function permission(): BelongsTo
    {
        return $this->belongsTo(Permission::class, 'permission_id');
    }
```

#### C. UserUpdateTablePermission — права на обновление полей

Таблица `user_update_table_permissions` задает, какие поля пользователь может обновлять:

```9:16:app/Services/UserUpdateTablePermission/Models/UserUpdateTablePermission.php
class UserUpdateTablePermission extends Model
{
    protected $fillable = ['user_id', 'permission_id'];

    public function permission(): BelongsTo
    {
        return $this->belongsTo(Permission::class, 'permission_id');
    }
```

### 3. Связь прав с полями метрик

В `config/metric_fields.php` определены маппинги прав на поля:

- `convert_permission_to_field` — права просмотра → поля (например, `'domain_view' => 'domain'`)
- `convert_update_permission_to_field` — права обновления → поля (например, `'domain_update' => 'domain'`)

Используется в `UserInfoTransformer` для определения доступных полей:

```81:101:app/Services/User/Transformers/UserInfoTransformer.php
    private function processUpdatePermissions(User $user, array &$data, array $updatePermissionsToFields): void
    {
        $user->userUpdateTablePermissions
            ->pluck('permission.name')
            ->each(function ($permission) use (&$data, $updatePermissionsToFields) {
                if (isset($data[$updatePermissionsToFields[$permission]])) {
                    $data[$updatePermissionsToFields[$permission]]['updatable'] = true;
                }
            });
    }

    private function processViewPermissions(User $user, array &$data, array $permissionsToFields): void
    {
        $user->userItemTablePermissions
            ->pluck('permission.name')
            ->each(function ($permission) use (&$data, $permissionsToFields) {
                if (isset($permissionsToFields[$permission]) && isset($data[$permissionsToFields[$permission]])) {
                    $data[$permissionsToFields[$permission]]['viewable'] = true;
                }
            });
    }
```

### 4. Проверка прав в приложении

#### В Middleware:

```20:20:bootstrap/app.php
            'permission' => \Spatie\Permission\Middleware\PermissionMiddleware::class,
```

Использование в роутах:
```php
->middleware('permission:update domain')
->middleware('permission:view all domain')
->middleware(['permission:approval domain', 'check-domain-flow-status'])
```

#### В коде:

```17:17:app/Services/Account/Middleware/CheckAccountAccess.php
        if ($user->can('view all account')) {
```

```50:73:app/Services/Domain/Transformers/ListDomainTransformer.php
            && $user->can('seo check domain');
        return $user->can('update domain');
            && $user->can('approval domain');
            && $user->can('buy domain');
            && $user->can('recovery domain');
```

### 5. Синхронизация прав пользователя

`UserSyncPermissionManager` синхронизирует три типа прав:

```18:23:app/Services/User/Managers/UserSyncPermissionManager.php
    public function syncPermissions(User $user, array $permissions): void
    {
        $this->syncMainPermissions($user, $permissions);
        $this->syncViewPermissions($user, $permissions);
        $this->syncUpdatePermissions($user, $permissions);
    }
```

- `main` — основные права (через Spatie)
- `view` — права просмотра полей
- `update` — права обновления полей

### 6. Структура данных пользователя

При получении информации о пользователе загружаются все типы прав:

```29:37:app/Services/User/Repo/UserRepo.php
    public function getInfo(User $user): User
    {
        return $user->load(
            'permissions',
            'roles',
            'userItemTablePermissions.permission',
            'userConfig',
            'userUpdateTablePermissions.permission'
        );
    }
```

## Итоговая схема

1. Роли и основные права — через Spatie Permission (стандартные CRUD-операции)
2. Права просмотра полей — через `user_item_table_permissions` (контроль видимости полей)
3. Права обновления полей — через `user_update_table_permissions` (контроль редактирования полей)
4. Конфигурация ролей — через `role_permission_configs` (типизация прав ролей)

Система позволяет:
- Контролировать доступ к маршрутам через middleware
- Контролировать видимость полей в таблицах
- Контролировать возможность редактирования конкретных полей
- Группировать права через роли

Это многоуровневая система контроля доступа с гранулярным управлением правами на уровне полей.