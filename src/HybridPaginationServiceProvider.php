<?php

namespace YounesMlik\HybridPagination;

use Illuminate\Support\ServiceProvider;

class HybridPaginationServiceProvider extends ServiceProvider
{
  /**
   * Bootstrap any application services.
   *
   * @return void
   */
  public function boot()
  {
    $views_path = '/../vendor/illuminate/pagination/resources/views';

    $this->loadViewsFrom(__DIR__ . $views_path, 'pagination');

    if ($this->app->runningInConsole()) {
      $this->publishes([
        __DIR__ . $views_path => $this->app->resourcePath('views/vendor/hybrid_pagination'),
      ], 'hybrid-pagination');
    }
  }

  /**
   * Register the service provider.
   *
   * @return void
   */
  public function register()
  {
    HybridPaginationState::resolveUsing($this->app);
  }
}
