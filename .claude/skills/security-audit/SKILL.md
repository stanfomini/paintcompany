---
name: security-audit
description: Security vulnerability scanning, OWASP compliance, automated fixes for common security issues.
---

# Security Audit Skill

## Security Checklist

### Authentication & Authorization
- [ ] All routes protected with middleware
- [ ] Policies used for authorization
- [ ] Password requirements enforced
- [ ] Rate limiting on auth endpoints

### Input Validation
- [ ] All user input validated
- [ ] Form Requests used for complex validation
- [ ] No raw SQL queries with user input
- [ ] XSS protection (using {{ }} not {!! !!})

### Data Protection
- [ ] CSRF protection on all forms
- [ ] Sensitive data encrypted
- [ ] No secrets in code or version control
- [ ] Database uses prepared statements

### Common Vulnerabilities

```bash
# SQL Injection - Find raw queries
grep -rn "DB::raw\|->whereRaw" app/

# XSS - Find unescaped output
grep -rn "{!!" resources/views/

# Mass Assignment - Check models
grep -L "fillable\|guarded" app/Models/*.php

# Unprotected Routes
grep -rn "Route::" routes/ | grep -v "middleware"
```

## Security Headers

```php
// app/Http/Middleware/SecurityHeaders.php
public function handle($request, Closure $next)
{
    $response = $next($request);

    $response->headers->set('X-Frame-Options', 'DENY');
    $response->headers->set('X-Content-Type-Options', 'nosniff');
    $response->headers->set('X-XSS-Protection', '1; mode=block');

    return $response;
}
```

## Audit Commands

```bash
# Dependency vulnerabilities
composer audit

# NPM vulnerabilities
npm audit

# Clear sensitive data from logs
php artisan cache:clear
php artisan config:clear
```
