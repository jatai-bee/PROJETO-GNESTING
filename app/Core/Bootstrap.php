<?php

declare(strict_types=1);

namespace GNesting\Core;

use Dotenv\Dotenv;
use GNesting\Repositories\ProductionSpecRepository;
use GNesting\Services\AuditService;
use GNesting\Services\ImageProcessor;
use GNesting\Services\ProductionFileService;
use RuntimeException;

/**
 * Inicializa a aplicação (web, CLI e testes): .env → config → container.
 */
final class Bootstrap
{
    public static function createContainer(string $basePath): Container
    {
        $basePath = rtrim($basePath, '/\\');

        if (is_file($basePath . '/.env')) {
            $dotenv = Dotenv::createImmutable($basePath);
            $dotenv->safeLoad();
            $dotenv->required(['APP_ENV', 'DB_HOST', 'DB_DATABASE', 'DB_USERNAME']);
        }

        $config = Config::fromDirectory($basePath . '/config');
        $config->set('paths.base', $basePath);
        $config->set('paths.storage', $basePath . '/storage');
        $config->set('paths.uploads', $basePath . '/public/uploads');
        // Testes nunca escrevem nos logs reais da aplicação
        $config->set('paths.logs', $config->get('app.env') === 'testing'
            ? sys_get_temp_dir() . '/gnesting-test-logs'
            : $basePath . '/storage/logs');

        // Em produção nunca exibir detalhes de erro, mesmo com APP_DEBUG=true por engano.
        if ($config->get('app.env') === 'production') {
            $config->set('app.debug', false);
            if ($config->get('app.key') === '') {
                throw new RuntimeException('APP_KEY não configurada. Execute: php bin/generate-key.php');
            }
        }

        date_default_timezone_set('UTC'); // banco e PHP em UTC; exibição via format_datetime()
        mb_internal_encoding('UTF-8');
        error_reporting(E_ALL);
        ini_set('display_errors', PHP_SAPI === 'cli' ? 'stderr' : '0');
        ini_set('log_errors', '1');
        // Stack traces sem argumentos: evita que senhas e tokens cheguem aos logs.
        ini_set('zend.exception_ignore_args', '1');
        ini_set('error_log', $config->get('paths.logs') . '/php-errors.log');

        $container = new Container();
        App::setContainer($container);

        $container->instance(Container::class, $container);
        $container->instance(Config::class, $config);

        $container->set(Logger::class, fn () => new Logger($config->get('paths.logs')));
        $container->set(Database::class, fn () => new Database($config->get('database')));
        $container->set(View::class, fn () => new View($basePath . '/app/Views'));
        $container->set(Session::class, fn () => new Session(
            $config->get('security.session'),
            $basePath . '/storage/sessions',
            ((string) $config->get('app.base_path', '')) . '/',
            PHP_SAPI !== 'cli',
        ));
        $container->set(ImageProcessor::class, fn () => new ImageProcessor(
            $config->get('paths.uploads'),
            (int) $config->get('uploads.max_image_bytes'),
        ));
        $container->set(ProductionFileService::class, fn (Container $c) => new ProductionFileService(
            $c->get(Database::class),
            $c->get(ProductionSpecRepository::class),
            $c->get(AuditService::class),
            $c->get(AuditContext::class),
            $basePath . '/storage/private/production_files',
            (int) $config->get('uploads.max_production_file_bytes'),
        ));
        $container->set(Router::class, function () use ($basePath): Router {
            $router = new Router();
            foreach (['web', 'admin', 'api'] as $file) {
                (require $basePath . "/routes/{$file}.php")($router);
            }

            return $router;
        });

        return $container;
    }
}
