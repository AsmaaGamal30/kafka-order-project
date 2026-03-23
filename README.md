# Laravel + Apache Kafka — Order Workflow

A mini e-commerce backend that demonstrates producing and consuming Kafka events
in a real-world order workflow. Built with Laravel and kafka.

---

## Architecture

```
HTTP Client
    │
    ▼
POST /api/orders
    │
    ├─► Save order to MySQL (status: pending)
    │
    └─► Publish  order.created ──────┬──► Confirmation Consumer
                                     │       → emails customer
                                     │       → updates status: confirmed
                                     │
                                     ├──► Inventory Consumer
                                     │       → decrements product stock
                                     │
                                     └──► Analytics Consumer
                                             → writes to order_events log

POST /api/orders/{id}/cancel
    │
    ├─► Update order status → cancelled
    │
    └─► Publish  order.cancelled ────┬──► Confirmation Consumer (cancel notice)
                                     ├──► Inventory Consumer   (restore stock)
                                     └──► Analytics Consumer   (log event)

Any consumer failure after N retries → order.dlq → DLQ Monitor
```

---

## Prerequisites

| Tool           | Version |
| -------------- | ------- |
| Docker         | 24+     |
| Docker Compose | 2.20+   |

That's it. PHP, Composer, and Kafka are all inside Docker.

---

## Quick Start

```bash
# 1. Clone and enter the project
git clone <repo-url> kafka-orders
cd kafka-orders

# 2. Copy environment file
cp .env.example .env

# 3. Start all containers
#    (first run takes ~3 min to download images and build)
docker compose up -d --build

# 4. Generate Laravel app key
docker compose exec app php artisan key:generate

# 5. Run migrations and seed sample products
docker compose exec app php artisan migrate --seed

# 6. Verify everything is running
docker compose ps
```

You should see 7 containers running:

- `kafka_orders_app` — Laravel / PHP-FPM
- `kafka_orders_nginx` — Web server on port 8000
- `kafka_orders_mysql` — MySQL 8
- `kafka_orders_zookeeper` — Kafka dependency
- `kafka_orders_kafka` — Kafka broker on port 9092
- `kafka_orders_ui` — Kafka UI on port 8080
- `kafka_orders_supervisor` — Runs all 4 consumers as daemons

---

## API Reference

Base URL: `http://localhost:8000/api`

### Create an order

```http
POST /api/orders
Content-Type: application/json

{
  "customer_name":  "Asmaa Gamal",
  "customer_email": "asmaa@example.com",
  "items": [
    { "product_id": "<uuid-from-db>", "quantity": 2 },
    { "product_id": "<uuid-from-db>", "quantity": 1 }
  ]
}
```

**Response 201:**

```json
{
  "message": "Order placed successfully.",
  "order": {
    "id": "019...",
    "customer_name": "Asmaa Gamal",
    "customer_email": "asmaa@example.com",
    "items": [...],
    "total": 189.97,
    "status": "pending",
    "created_at": "2024-01-15T10:30:00Z"
  }
}
```

### Get an order

```http
GET /api/orders/{id}
```

### Cancel an order

```http
POST /api/orders/{id}/cancel
Content-Type: application/json

{
  "reason": "Changed my mind"
}
```

---

## Trying it out step by step

### Step 1 — Get a product ID to use

```bash
docker compose exec mysql mysql -uroot -psecret kafka_orders \
  -e "SELECT id, name, price, stock FROM products;"
```

Copy one of the UUIDs.

### Step 2 — Place an order

```bash
curl -s -X POST http://localhost:8000/api/orders \
  -H "Content-Type: application/json" \
  -d '{
    "customer_name":  "Asmaa Gamal",
    "customer_email": "asmaa@example.com",
    "items": [
      { "product_id": "PASTE-UUID-HERE", "quantity": 2 }
    ]
  }' | jq .
```

### Step 3 — Watch the consumers process it

```bash
# Confirmation consumer log
docker compose exec supervisor tail -f /var/www/storage/logs/confirmation-consumer.log

# Inventory consumer log
docker compose exec supervisor tail -f /var/www/storage/logs/inventory-consumer.log

# Analytics consumer log
docker compose exec supervisor tail -f /var/www/storage/logs/analytics-consumer.log
```

You should see each consumer log its activity within a second.

### Step 4 — Check the database

```bash
# See order status (should be 'confirmed' after confirmation consumer runs)
docker compose exec mysql mysql -uroot -psecret kafka_orders \
  -e "SELECT id, status, total FROM orders ORDER BY created_at DESC LIMIT 5;"

# See product stock (should have decreased)
docker compose exec mysql mysql -uroot -psecret kafka_orders \
  -e "SELECT name, stock FROM products;"

# See analytics events
docker compose exec mysql mysql -uroot -psecret kafka_orders \
  -e "SELECT order_id, event_type, occurred_at FROM order_events;"
```

### Step 5 — Cancel the order

```bash
# Copy the order ID from step 2, then:
curl -s -X POST http://localhost:8000/api/orders/ORDER-ID-HERE/cancel \
  -H "Content-Type: application/json" \
  -d '{"reason": "Testing cancellation flow"}' | jq .
```

Watch the logs again — all three consumers will react to the cancellation.

### Step 6 — Trigger the Dead Letter Queue

To simulate a consumer failure, temporarily corrupt a message. The easiest
way is to publish directly from the Kafka UI:

1. Open http://localhost:8080
2. Navigate to **Topics** → `order.created`
3. Click **Produce Message**
4. Paste this invalid payload (missing required fields):
    ```json
    { "event_type": "order.created", "broken": true }
    ```
5. Watch the DLQ monitor log:
    ```bash
    docker compose exec supervisor tail -f /var/www/storage/logs/dlq-monitor.log
    ```

---

## Running the tests

```bash
# Run all tests (uses an in-memory SQLite DB — no Kafka needed)
docker compose exec app php artisan test

# With coverage
docker compose exec app php artisan test --coverage
```

The test suite mocks the `KafkaProducerService` so tests run without a live
broker. Consumer logic is tested by calling their `handle()` method directly.

---

## Project Structure

```
kafka-orders/
├── app/
│   ├── Console/Commands/
│   │   ├── BaseConsumer.php          ← shared retry + DLQ logic
│   │   ├── ConsumeConfirmation.php   ← sends customer notifications
│   │   ├── ConsumeInventory.php      ← reserves / releases stock
│   │   ├── ConsumeAnalytics.php      ← writes to event log
│   │   └── ConsumeDlq.php            ← monitors failed messages
│   ├── Http/
│   │   ├── Controllers/OrderController.php
│   │   └── Requests/
│   │       ├── StoreOrderRequest.php
│   │       └── CancelOrderRequest.php
│   ├── Models/
│   │   ├── Order.php
│   │   ├── Product.php
│   │   └── OrderEvent.php
│   └── Services/
│       └── KafkaProducerService.php  ← single publish entry-point
├── config/
│   └── kafka.php                     ← all Kafka settings in one place
├── database/migrations/
├── docker/
│   ├── Dockerfile
│   ├── nginx.conf
│   └── supervisord.conf
├── routes/api.php
├── tests/Feature/
│   ├── CreateOrderTest.php
│   ├── CancelOrderTest.php
│   └── KafkaConsumerTest.php
└── docker-compose.yml
```

---

## Kafka Topics

| Topic             | Published by | Consumed by                                  |
| ----------------- | ------------ | -------------------------------------------- |
| `order.created`   | Orders API   | Confirmation, Inventory, Analytics consumers |
| `order.cancelled` | Orders API   | Confirmation, Inventory, Analytics consumers |
| `order.events`    | (reserved)   | Reporting / audit queries                    |
| `order.dlq`       | BaseConsumer | DLQ Monitor consumer                         |

---

## Error Handling Strategy

```
Message arrives at consumer
        │
        ▼
   Decode JSON?  ──No──► Publish to DLQ immediately (unrecoverable)
        │
       Yes
        │
        ▼
  All required   ──No──► Publish to DLQ immediately (unrecoverable)
  fields present?
        │
       Yes
        │
        ▼
   handle() called
        │
     Success? ──Yes──► Commit offset, done
        │
        No (exception)
        │
        ▼
   attempt < MAX_RETRIES?  ──Yes──► sleep(5s) → retry
        │
        No (retries exhausted)
        │
        ▼
   Publish to order.dlq
   DLQ Monitor logs the failure
```

---

## Stopping the project

```bash
docker compose down          # stop containers, keep DB data
docker compose down -v       # stop containers AND delete DB data
```
