# DariKruv

Backend за платформа за кръводаряване. Приложението се пуска локално чрез Docker: Nginx, PHP 8.2, MySQL 8 и phpMyAdmin.

## Как да го пробвам локално

### 1. Какво ти трябва

- [Git](https://git-scm.com/downloads)
- [Docker Desktop](https://www.docker.com/products/docker-desktop/) (включва Docker Compose)

Провери, че Docker работи:

```bash
docker --version
docker compose version
```

### 2. Издърпай проекта с Git

```bash
git clone https://github.com/vapetrov99/darikruv.bg.git
cd darikruv.bg
```

Ако вече имаш копие и искаш последните промени:

```bash
git pull origin main
```

### 3. Стартирай контейнерите

От главната папка на проекта:

```bash
docker compose up --build
```

Първото пускане може да отнеме няколко минути (сваляне на образи и билд на PHP). Остави терминала отворен.

При първо стартиране MySQL автоматично създава базата `darikruv` и зарежда таблиците от `database/schema.sql`.

### 4. Провери, че работи

| Услуга | Адрес |
| --- | --- |
| Приложение (PHP) | http://localhost:8080 |
| API | http://localhost:8080/api/index.php |
| Проверка на базата | http://localhost:8080/test-db.php |
| phpMyAdmin | http://localhost:8081 |

Очаквано поведение:

- `http://localhost:8080` показва `DariKruv backend works!` и PHP info
- `http://localhost:8080/test-db.php` показва `Database connection successful!` и списък с таблици (`users`, `donors`, `blood_requests`, `request_responses`)
- `http://localhost:8080/api/index.php?route=users` връща JSON със списък потребители (празен масив, ако още няма данни)

### 5. Данни за базата (локално)

Тези стойности са само за локална разработка:

- хост от PHP контейнера: `mysql`
- хост от твоя компютър: `127.0.0.1`
- порт: `3306`
- база: `darikruv`
- потребител: `root`
- парола: `root`

phpMyAdmin използва същите данни.

### 6. Примерен API тест

Регистрация на потребител:

```bash
curl -X POST "http://localhost:8080/api/index.php?route=register" \
  -H "Content-Type: application/json" \
  -d '{
    "first_name": "Ivan",
    "last_name": "Petrov",
    "email": "ivan@example.com",
    "password": "123456",
    "phone": "+359888123456",
    "city": "Sofia",
    "is_donor": true,
    "blood_type": "A+"
  }'
```

Отговорът съдържа `verification_link`. Отвори го в браузър, за да потвърдиш имейла, след което можеш да влезеш с `route=login`.

Други полезни routes:

- `GET /api/index.php?route=users`
- `GET /api/index.php?route=donors`
- `GET /api/index.php?route=requests`
- `POST /api/index.php?route=login`
- `POST /api/index.php?route=create_request`

### 7. Спиране и повторно пускане

Спиране (контейнерите остават, данните в MySQL се пазят):

```bash
docker compose down
```

Повторно пускане без пълен rebuild:

```bash
docker compose up
```

Пълно нулиране на базата (изтрива MySQL данните и при следващо стартиране схемата се зарежда наново):

```bash
docker compose down -v
docker compose up --build
```

## Ако нещо не тръгва

- Портовете `8080`, `8081` и `3306` трябва да са свободни.
- Изчакай MySQL да стане готов (10–20 секунди след `docker compose up`) преди да отваряш `test-db.php`.
- Ако таблиците липсват, нулирай тома с `docker compose down -v` и стартирай отново.
- Провери логовете с `docker compose logs`.
