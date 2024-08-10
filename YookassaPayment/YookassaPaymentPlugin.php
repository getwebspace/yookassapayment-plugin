<?php declare(strict_types=1);

namespace Plugin\YookassaPayment;

use App\Domain\Entities\Catalog\Order;
use App\Domain\Models\CatalogOrder;
use App\Domain\Plugin\AbstractPaymentPlugin;
use App\Domain\Service\Catalog\OrderService as CatalogOrderService;
use Psr\Container\ContainerInterface;

class YookassaPaymentPlugin extends AbstractPaymentPlugin
{
    const AUTHOR = 'Aleksey Ilyin';
    const AUTHOR_SITE = 'https://getwebspace.org';
    const NAME = 'YookassaPaymentPlugin';
    const TITLE = 'YookassaPayment';
    const DESCRIPTION = 'Возможность принимать безналичную оплату товаров и услуг';
    const VERSION = '1.0.1';

    public function __construct(ContainerInterface $container)
    {
        parent::__construct($container);

        $this->addSettingsField([
            'label' => 'Идентификатор магазина',
            'type' => 'text',
            'name' => 'id',
        ]);

        $this->addSettingsField([
            'label' => 'Секретный ключ',
            'type' => 'text',
            'name' => 'secret',
        ]);

        $this->addSettingsField([
            'label' => 'Description',
            'description' => 'В указанной строке <code>{serial}</code> заменится на номер заказа',
            'type' => 'text',
            'name' => 'description',
            'args' => [
                'value' => 'Оплата заказа #{serial}',
            ],
        ]);

        $this->addSettingsField([
            'label' => 'Система налогообложения',
            'type' => 'select',
            'name' => 'sno',
            'args' => [
                'option' => [
                    '1' => 'Общая СН',
                    '2' => 'Упрощенная СН (доходы)',
                    '3' => 'Упрощенная СН (доходы минус расходы)',
                    '4' => 'Единый налог на вмененный доход (ЕНВД)',
                    '5' => 'Единый сельскохозяйственный налог (ЕСН)',
                    '6' => 'Патентная система налогообложения',
                ],
            ],
        ]);

        $this->addSettingsField([
            'label' => 'Налоговая ставка',
            'type' => 'select',
            'name' => 'tax',
            'args' => [
                'option' => [
                    '1' => 'Без НДС',
                    '2' => 'НДС чека по ставке 0%',
                    '3' => 'НДС чека по ставке 10%',
                    '4' => 'НДС чека по расчетной ставке 20/120',
                    '5' => 'НДС чека по расчетной ставке 20/120',
                    '6' => 'НДС чека по расчетной ставке 20/120',
                ],
            ],
        ]);

        // результат оплаты
        $this
            ->map([
                'methods' => ['get', 'post'],
                'pattern' => '/cart/done/yk/result',
                'handler' => \Plugin\YookassaPayment\Actions\ResultAction::class,
            ])
            ->setName('common:yk:success');
    }

    public function getRedirectURL(CatalogOrder $order): ?string
    {
        $this->logger->debug('YookassaPayment: register order', ['serial' => $order->serial]);

        $receipt = [
            'customer' => [
                'full_name' => $order->delivery['client'],
                'email' => $order->email,
                'phone' => $order->phone,
            ],
            'tax_system_code' => $this->parameter('YookassaPaymentPlugin_sno', '1'),
            'items' => [],
        ];

        foreach ($order->products as $product) {
            if ($product->price() > 0) {
                $receipt['items'][] = [
                    'description' => $product->title,
                    'quantity' => $product->totalCount(),
                    'amount' => [
                        'value' => $product->totalPrice(),
                        'currency' => 'RUB',
                    ],
                    'vat_code' => $this->parameter('YookassaPaymentPlugin_tax', '1'),
                ];
            }
        }

        // регистрация заказа
        $result = $this->request('POST', 'payments', [
            'amount' => [
                'value' => $order->totalSum(),
                'currency' => 'RUB',
            ],
            'confirmation' => [
                'type' => 'redirect',
                'return_url' => $this->parameter('common_homepage') . 'cart/done/yk/result?serial=' . $order->serial,
                'locale' => 'ru_RU',
            ],
            'receipt' => $receipt,
            'description' => str_replace('{serial}', $order->serial, $this->parameter('YookassaPaymentPlugin_description', '')),
            'metadata' => [
                'serial' => $order->serial,
            ],
            'capture' => true,
        ], $order->uuid);

        if ($result && !empty($result['confirmation']['confirmation_url'])) {
            $this->container->get(CatalogOrderService::class)->update($order, ['system' => $result['id']]);

            return $result['confirmation']['confirmation_url'];
        }

        return null;
    }

    public function request(string $method = 'GET', string $endpoint = '', array $data = [], string $idempotence = ''): mixed
    {
        $auth = implode(':', [$this->parameter('YookassaPaymentPlugin_id'), $this->parameter('YookassaPaymentPlugin_secret')]);

        $url = 'https://api.yookassa.ru/v3/';
        $url = "{$url}{$endpoint}";

        $ch = curl_init($url);

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_USERPWD, $auth);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Basic ' . base64_encode($auth),
            'Idempotence-Key: ' . $idempotence,
            'Content-Type: application/json'
        ]);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }

        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_VERBOSE, true);

        $response = curl_exec($ch);
        $size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $headers = substr($response, 0, $size);
        $result = substr($response, $size);

        curl_close($ch);

        $this->logger->debug('YookassaPayment: request', ['url' => $url, 'data' => $data]);
        $this->logger->debug('YookassaPayment: response', ['headers' => $headers, 'response' => $result]);

        if ($result) {
            $json = array_merge(['type' => false], json_decode($result, true));

            if (!$json['type']) {
                return $json;
            }
        }

        return false;
    }
}
