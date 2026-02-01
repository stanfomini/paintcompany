---
description: Run complete test suite with coverage analysis
---

# Run Complete Test Suite

## Execution Steps

1. **Clear caches**
```bash
php artisan config:clear
php artisan cache:clear
php artisan view:clear
```

2. **Run all tests with coverage**
```bash
php artisan test --compact --coverage --min=80
```

3. **Run static analysis**
```bash
./vendor/bin/phpstan analyse --memory-limit=2G
```

4. **Check code style**
```bash
./vendor/bin/pint --test
```

5. **Generate coverage report**
If coverage < 80%, identify untested areas and create tasks for test creation.
