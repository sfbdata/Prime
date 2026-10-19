<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\UseCase;

use App\TrabalhoExterno\DTO\DadosDaSolicitacao;
use App\TrabalhoExterno\DTO\DadosDaVaga;
use App\TrabalhoExterno\Enum\Perfil;
use App\TrabalhoExterno\Enum\TipoItemMarcado;
use App\TrabalhoExterno\Repository\MarcacaoDoUsuarioRepository;
use App\TrabalhoExterno\Service\ApresentadorDeVaga;
use App\TrabalhoExterno\Service\Contexto;
use App\TrabalhoExterno\Service\LocalizadorDeVaga;
use App\TrabalhoExterno\Service\PoliticaDaVaga;

/**
 * A aba Vagas de uma vez (spec VS 18.10.26 §4.1): os cartões que o perfil vê (menos os que ele
 * excluiu da lista), o que ele pode fazer, as listas do formulário e, para o Master, a configuração
 * interna. Consulta: não grava nada (o vencimento só é contado, não gravado).
 */
final class ListarVagasUseCase
{
    public function __construct(
        private readonly LocalizadorDeVaga $localizador,
        private readonly PoliticaDaVaga $politica,
        private readonly ApresentadorDeVaga $apresentador,
        private readonly MarcacaoDoUsuarioRepository $marcacoes,
    ) {
    }

    /** @return array<string, mixed> */
    public function executar(Contexto $ctx): array
    {
        $agora = new \DateTimeImmutable();
        $vagas = $this->localizador->visiveis($ctx, $agora);
        $marcas = $ctx->previa ? [] : $this->marcacoes->doUsuario($ctx->usuario, TipoItemMarcado::Vaga, array_map(static fn ($v): int => (int) $v->getId(), $vagas));

        $cartoes = [];
        foreach ($vagas as $v) {
            $marca = $marcas[(int) $v->getId()] ?? null;
            if ($marca?->excluida() !== true) {
                $cartoes[] = $this->apresentador->cartao($ctx, $v, $marca, $agora);
            }
        }

        return [
            'veAba' => $this->politica->veAba($ctx),
            'daCentral' => $this->politica->ehDaCentral($ctx),
            'podeCriar' => $this->politica->podeCriar($ctx),
            'podeDivulgar' => $this->politica->podeDivulgar($ctx),
            'agora' => $agora->format(\DATE_ATOM),
            'vagas' => $cartoes,
            'listas' => $this->listas(),
            'configuracao' => $ctx->perfil === Perfil::Master && !$ctx->previa ? $ctx->configuracao->getVagas() : null,
            'ofertantePadrao' => $this->ofertantePadrao($ctx),
        ];
    }

    /** @return array<string, list<mixed>> */
    private function listas(): array
    {
        return [
            'areas' => DadosDaVaga::AREAS,
            'cargos' => DadosDaVaga::CARGOS,
            'contratacoes' => DadosDaVaga::CONTRATACOES,
            'modalidades' => DadosDaVaga::MODALIDADES,
            'ufs' => DadosDaSolicitacao::UFS,
            'periodos' => DadosDaVaga::PERIODOS,
            'duracoesDaOferta' => DadosDaVaga::DURACOES_DA_OFERTA,
        ];
    }

    /**
     * "QUEM ESTÁ OFERECENDO" já preenchido: o escritório atual, ou a Central. Os dados salvos "como
     * padrão para as próximas vagas" ficam para depois do zip (a regra se perdeu).
     *
     * @return array<string, string>
     */
    private function ofertantePadrao(Contexto $ctx): array
    {
        $t = $this->politica->ehDaCentral($ctx) ? null : $ctx->tenant;

        return [
            'nome' => $t !== null ? (string) $t->getName() : 'Central BlueJus',
            'resp' => trim((string) $ctx->usuario->getFullName()),
            'cidade' => (string) $t?->getCidade(),
            'uf' => (string) $t?->getEstado(),
            'email' => (string) $ctx->usuario->getEmail(),
        ];
    }
}
