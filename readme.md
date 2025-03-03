# Hybrid Pagination for Laravel Eloquent  

[![Laravel](https://img.shields.io/badge/Laravel-^11.0-red.svg)](https://laravel.com)  
[![PHP](https://img.shields.io/badge/PHP-^8.0-blue.svg)](https://www.php.net)  
[![License](https://img.shields.io/github/license/YounesMlik/hybrid-pagination)](LICENSE)  

Hybrid Pagination is a scalable pagination solution that enhances Laravel’s Eloquent ORM by combining the convenience of offset-based with the performance of cursor-based pagination. This allows:

- **Direct page jumps (offset-style navigation)**

- **Efficient handling of large datasets (cursor-based optimization)**

- **Significantly lower database expense**

Unlike traditional pagination methods, Hybrid Pagination provides random page access without sacrificing performance.

## Features  

✅ Fluent API similar to Laravel’s built-in pagination.  
✅ Works with Eloquent – Supports complex queries and relationships.  
✅ Optimized for Large Datasets – Avoids deep offset performance issues.  
✅ Customizable Navigation – Configure cursor fields, page names, and total count behavior.

## Installation  

```bash
composer require younes_mlik/hybrid-pagination
```

## Usage  

### Basic Usage  

```php
namespace App\Models;

use App\Traits\CanHybridPaginate;

class User extends Model
{
    use CanHybridPaginate;

    ...
``` 

```php
$users = User::
    orderBy("first_name")->
    orderBy("id")->
    hybridPaginate(4, "*");
```

## Configuration  

You can publish the configuration file:  

```bash
php artisan vendor:publish --tag=hybrid-pagination-config
```

Config file (`config/hybrid_pagination.php`):  

```php
return [
    'default_cursor_field' => 'id',
    'threshold' => 1000, // Switch to cursor pagination when offset exceeds this value
];
```

## Why Use Hybrid Pagination?  

| Feature               | Offset Pagination | Cursor Pagination | Hybrid Pagination |
|-----------------------|------------------|------------------|------------------|
| Random page access   | ✅ | ❌ | ✅ |
| Performance on large datasets | ❌ | ✅ | ✅ |

## License  

This package is open-sourced software licensed under the [MIT license](https://opensource.org/license/mit).  
