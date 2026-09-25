<?php

declare(strict_types=1);

namespace GNesting\Core;

use Dotenv\Dotenv;
use GNesting\Repositories\ProductionSpecRepository;
use GNesting\Services\AuditService;
use GNesting\Services\ImageProcessor;
use GNesting\Services\Mail\LogMailer;
use GNesting\Services\Mail\Mailer;
use GNesting\Services\Mail\NativeMailer;
use GNesting\Services\Mail\SmtpMailer;
use GNesting\Services\Mail\StreamSmtpTransport;
use GNesting\Services\Payment\CurlHttpClient;
use GNesting\Services\Payment\HttpClient;
use GNesting\Services\Payment\MercadoPagoGateway;
use GNesting\Services\Payment\PaymentGateway;
use GNesting\Services\Payment\SimulatedGateway;
use GNesting\Services\ProductionFileService;
use GNesting\Services\Shipping\ShippingCalculator;
use GNesting\Services\Shipping\TableShippingCalculator;
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
        $container->set(ShippingCalculator::class, fn () => new TableShippingCalculator($config));
        $container->set(HttpClient::class, fn () => new CurlHttpClient());
        $container->set(PaymentGateway::class, function (Container $c) use ($config): PaymentGateway {
            return match ($config->get('payment.provider')) {
                'mercadopago' => new MercadoPagoGateway(
                    $c->get(HttpClient::class),
                    (string) $config->get('payment.mercadopago.access_token'),
                    (string) $config->get('payment.mercadopago.webhook_secret'),
                    (int) $config->get('payment.mercadopago.max_installments', 12),
                ),
                // O simulado aprova pagamentos com um clique: nunca em produção
                'simulado' => $config->get('app.env') === 'production'
                    ? throw new RuntimeException('PAYMENT_PROVIDER=simulado não é permitido em produção.')
                    : new SimulatedGateway(),
                default => throw new RuntimeException('PAYMENT_PROVIDER inválido: use mercadopago.'),
            };
        });
        $container->set(Mailer::class, fn (Container $c) => match ($config->get('mail.driver')) {
            'smtp' => new SmtpMailer(
                new StreamSmtpTransport(),
                (string) $config->get('mail.smtp.host'),
                (int) $config->get('mail.smtp.port'),
                (string) $config->get('mail.smtp.encryption'),
                (string) $config->get('mail.smtp.username'),
                (string) $config->get('mail.smtp.password'),
                (string) $config->get('mail.from_address'),
                (string) $config->get('mail.from_name'),
                static fn (string $error) => $c->get(Logger::class)->error('SMTP: ' . $error),
                (string) (parse_url((string) $config->get('app.url'), PHP_URL_HOST) ?: 'localhost'),
            ),
            'mail' => new NativeMailer((string) $config->get('mail.from_address'), (string) $config->get('mail.from_name')),
            default => new LogMailer($config->get('paths.logs')),
        });
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
