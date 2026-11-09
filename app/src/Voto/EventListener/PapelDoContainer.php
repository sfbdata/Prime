<?php

declare(strict_types=1);

namespace App\Voto\EventListener;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * "Mesma aplicação, dois papéis" (spec docs/specs/bluejus-voto.md, D1-B): o mesmo código roda no container `php` do
 * BlueJus e no container próprio `voto`, e cada um só responde às rotas do seu papel.
 *
 * O papel vem da variável APP_PAPEL, que só o serviço `voto` do docker-compose.prod.yml define (`voto`). Vazia ou
 * ausente vale `bluejus`, então o BlueJus, o dev e os testes não precisam dela.
 *  - papel `voto`: só as rotas cujo nome começa com `voto_publico_` (portal, cabine, urna, verificador e a saúde do
 *    container); qualquer outra responde 404, inclusive o login;
 *  - papel `bluejus`: as rotas `voto_publico_` respondem 404, porque o lugar delas é o endereço do Voto.
 *
 * Valor desconhecido fecha como o papel `voto` (só as rotas públicas do Voto): erro de digitação no container do Voto
 * nunca expõe o BlueJus inteiro no endereço do Voto.
 *
 * Prioridade 16: depois do roteamento (32), para o nome da rota já existir, e antes do firewall (8) e do guarda de
 * recurso do Master (3): rota do outro papel nem chega a abrir sessão ou consultar o banco. Só a requisição
 * principal: a página de erro é sub-requisição e precisa passar.
 *
 * Grava o papel em `_papel` nos atributos da requisição principal, para a tela saber onde está: a página "em
 * preparação" do guarda de recurso esconde o "Voltar ao Expediente" no papel `voto`, onde o Expediente é 404.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 16)]
final class PapelDoContainer
{
    public const PAPEL_BLUEJUS = 'bluejus';
    public const PAPEL_VOTO = 'voto';
    public const PREFIXO_DAS_ROTAS_DO_VOTO = 'voto_publico_';
    public const ATRIBUTO = '_papel';

    private readonly string $papel;

    public function __construct(
        #[Autowire('%env(default::APP_PAPEL)%')]
        ?string $papel,
    ) {
        $this->papel = self::papelEfetivo($papel);
    }

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $requisicao = $event->getRequest();
        $requisicao->attributes->set(self::ATRIBUTO, $this->papel);
        $rota = (string) $requisicao->attributes->get('_route', '');
        $rotaDoVoto = str_starts_with($rota, self::PREFIXO_DAS_ROTAS_DO_VOTO);
        if ($rotaDoVoto !== ($this->papel === self::PAPEL_VOTO)) {
            throw new NotFoundHttpException('Rota fora do papel deste container.');
        }
    }

    private static function papelEfetivo(?string $papel): string
    {
        $papel = strtolower(trim((string) $papel));
        if ($papel === '' || $papel === self::PAPEL_BLUEJUS) {
            return self::PAPEL_BLUEJUS;
        }

        return self::PAPEL_VOTO;
    }
}
