# Мигратор данных

Есть старая база данных, и есть новая база данных.

В вашем приложении есть два соединения: `default` для новой базы и `legacy` для старой.

Напишем первую миграцию:

```php
class MigrateUsers extends \Fcz\Migrator\Migration 
{
    public function table(): string
    {
        return 'users';
    }

    public function query(): Builder
    {
        return DB::connection('legacy')
            ->table('site_users');
    }

    public function keyName(): string
    {
        return 'u_id';
    }

    public function dependsOn(): array
    {
        return [
            // new AnotherMigration()
        ];
    }

    public function migrate(stdClass $row): bool
    {
        $roles = $this->roles($row);

        $data = [
            'name'           => $row->u_fio,
            'email'          => $row->u_email,
            'roles'          => json_encode(explode(', ', $row->u_roles ?? '')),
            'external'       => $row->u_freelancer,
            'enabled'        => $row->u_status,
            'deactivated_at' => $row->u_deactivate_at,
            'created_at'     => now(),
            'updated_at'     => now(),
        ];

        return DB::table($this->table())
            ->updateOrInsert(['id' => $row->{$this->keyName()}], $data);
    }
}
```

Напишем консольную команду и добавим в неё нашу миграцию:

```php
class MigrateCommand extends \Fcz\Migrator\MigrateCommand
{
    public function logger(): ?LoggerInterface
    {
        return null;
    }
    
    public function migrations(): array
    {
        return [
            'Users' => fn() => new MigrateUsers(),        
        ];   
    }
}
```

Выполним миграции:

```shell
php artisan migrate:fresh
php artisan migrate:data
```

Мигратор работает инкрементально, запускается с места последней остановки. 
Чтобы перезапустить миграцию данных с начала, выполните:

```shell
php artisan migrate:data --reset
```