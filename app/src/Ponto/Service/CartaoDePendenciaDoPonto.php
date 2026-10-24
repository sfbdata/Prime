<?php

declare(strict_types=1);

namespace App\Ponto\Service;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Ponto\DTO\PendenciaDeRegistroOutput;
use App\Ponto\UseCase\ListarPendenciasDeRegistroUseCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * O cartão diário de pendência de registro (spec `ponto-esquecimento-nao-trava.md` R9; componente do lembrete do
 * desenho, bj-lembretes.js): os textos já traduzidos e o link, para o JS só desenhar com textContent. Null fora do
 * público dos avisos de ponto (R7) ou sem pendência.
 *
 * A pendência do cartão é a mais antiga deste escritório, onde o pedido sai pelo modal da tela do ponto; sem ela, a
 * mais antiga de outro escritório, com a orientação de trocar (o nome do escritório só vai para a própria pessoa).
 */
final class CartaoDePendenciaDoPonto
{
    public function __construct(
        private readonly PublicoDosAvisosDePonto $publico,
        private readonly ListarPendenciasDeRegistroUseCase $pendencias,
        private readonly TranslatorInterface $tradutor,
        private readonly UrlGeneratorInterface $rotas,
    ) {
    }

    /**
     * @return array{rotulo: string, titulo: string, linha: string, orientacao: ?string, acao: string, link: string, adiar: string, dispensar: string, regiao: string}|null
     */
    public function de(User $eu, Tenant $tenant): ?array
    {
        if (!$this->publico->recebe($eu, $tenant)) {
            return null;
        }
        $lista = $this->pendencias->executar($eu, $tenant);
        if ($lista === []) {
            return null;
        }
        $daqui = array_values(array_filter($lista, static fn (PendenciaDeRegistroOutput $p): bool => $p->atual));
        $alvo = $daqui !== [] ? $daqui[count($daqui) - 1] : $lista[count($lista) - 1];   // a lista vem da mais recente
        $dia = (new \DateTimeImmutable($alvo->dia))->format('d/m/Y');
        $falta = $this->tradutor->trans('ponto_pendencias.falta.' . $alvo->tipo);

        return [
            'rotulo' => $this->tradutor->trans('ponto_cartao.rotulo', ['n' => count($lista)]),
            'titulo' => $this->tradutor->trans('ponto_cartao.titulo'),
            'linha' => $alvo->atual
                ? $this->tradutor->trans('ponto_cartao.linha', ['dia' => $dia, 'falta' => $falta])
                : $this->tradutor->trans('ponto_cartao.linha_outro', ['dia' => $dia, 'falta' => $falta, 'escritorio' => $alvo->escritorio]),
            'orientacao' => $alvo->atual ? null : $this->tradutor->trans('ponto_pendencias.outro_escritorio', ['escritorio' => $alvo->escritorio]),
            'acao' => $this->tradutor->trans($alvo->atual ? 'ponto_pendencias.enviar' : 'ponto_pendencias.trocar'),
            'link' => $alvo->atual
                ? $this->rotas->generate('ponto_index', ['competencia' => substr($alvo->dia, 0, 7), 'esquecimento' => $alvo->dia . ':' . $alvo->tipo])
                : $this->rotas->generate('tenant_selecionar'),
            'adiar' => $this->tradutor->trans('ponto_cartao.adiar'),
            'dispensar' => $this->tradutor->trans('ponto_cartao.dispensar'),
            'regiao' => $this->tradutor->trans('ponto_cartao.regiao'),
        ];
    }
}
