<?php declare(strict_types=1);

namespace Plugin\YookassaPayment\Actions;

use App\Application\Actions\Cup\Catalog\CatalogAction;
use Plugin\YookassaPayment\YookassaPaymentPlugin;

class ResultAction extends CatalogAction
{
    protected function action(): \Slim\Psr7\Response
    {
        $default = [
            'serial' => '',
            'orderId' => '',
        ];
        $data = array_merge($default, $this->request->getQueryParams());

        $this->logger->debug('YookassaPayment: check', ['data' => $data]);

        $order = $this->catalogOrderService->read(['serial' => $data['serial']]);

        if ($order) {
            /** @var YookassaPaymentPlugin $tp */
            $tp = $this->container->get('YookassaPaymentPlugin');

            $result = $tp->request('GET', 'payments/' . $order->system);

            if ($result && !empty($result['status']) && ($result['status'] == 'succeeded' || $result['status'] == 'waiting_for_capture')) {
                $this->container->get(\App\Application\PubSub::class)->publish('plugin:order:payment', $order);
            }

            return $this->respondWithRedirect('/cart/done/' . $order->uuid);
        }

        return $this->respondWithRedirect('/');
    }
}
