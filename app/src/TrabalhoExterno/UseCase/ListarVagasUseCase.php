<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\UseCase;

use App\TrabalhoExterno\DTO\DadosDaSolicitacao;
use App\TrabalhoExterno\DTO\DadosDaVaga;
use App\TrabalhoExterno\Entity\PreferenciaDoUsuario;
use App\TrabalhoExterno\Entity\Vaga;
use App\TrabalhoExterno\Enum\Perfil;
use App\TrabalhoExterno\Enum\SituacaoVaga;
use App\TrabalhoExterno\Enum\TipoItemMarcado;
use App\TrabalhoExterno\Repository\MarcacaoDoUsuarioRepository;
use App\TrabalhoExterno\Repository\PreferenciaDoUsuarioRepository;
use App\TrabalhoExterno\Service\ApresentadorDeVaga;
use App\TrabalhoExterno\Service\CandidaturasDaVaga;
use App\TrabalhoExterno\Service\Contexto;
use App\TrabalhoExterno\Service\LocalizadorDeVaga;
use App\TrabalhoExterno\Service\PoliticaDaVaga;
use App\TrabalhoExterno\Service\ResumoDasCandidaturas;

/**
 * A aba Vagas de uma vez (spec VS 18.10.26 §4.1, desenho 06 l.1113–1118): os cartões que o perfil vê
 * (menos os que ele excluiu da lista), o que ele pode fazer, as listas do formulário, o padrão de "QUEM
 * ESTÁ OFERECENDO" e, para o Master, a configuração interna. O interno vê pela data, a mais nova
 * primeiro; o externo vê as ofertas ao vivo antes e, depois, as mais compatíveis com o currículo dele
 * (l.1117). Consulta: não grava nada (a visita é gravada por `RegistrarVisitaAsVagasUseCase`).
 */
final class ListarVagasUseCase
{
    public function __construct(
        private readonly LocalizadorDeVaga $localizador,
        private readonly PoliticaDaVaga $politica,
        private readonly ApresentadorDeVaga $apresentador,
        private readonly MarcacaoDoUsuarioRepository $marcacoes,
        private readonly PreferenciaDoUsuarioRepository $preferencias,
        private readonly ResumoDasCandidaturas $resumo,
    ) {
    }

    /** @return array<string, mixed> */
    public function executar(Contexto $ctx): array
    {
        $agora = new \DateTimeImmutable();
        $visiveis = $this->localizador->visiveis($ctx, $agora);
        $resumos = $this->resumo->paraLista($ctx, $visiveis);
        $vagas = $this->ordenar($ctx, $visiveis, $resumos, $agora);
        $marcas = $ctx->previa ? [] : $this->marcacoes->doUsuario($ctx->usuario, TipoItemMarcado::Vaga, array_map(static fn (Vaga $v): int => (int) $v->getId(), $vagas));
        $ultimaVisita = $this->ultimaVisita($ctx);

        $cartoes = [];
        foreach ($vagas as $v) {
            $marca = $marcas[(int) $v->getId()] ?? null;
            if ($marca?->excluida() !== true) {
                $cartoes[] = $this->apresentador->cartao($ctx, $v, $marca, $ultimaVisita, $agora, $resumos[(int) $v->getId()] ?? null);
            }
        }

        return [
            'veAba' => $this->politica->veAba($ctx),
            'interno' => $this->politica->ehInterno($ctx),
            'daCentral' => $this->politica->ehDaCentral($ctx),
            'master' => $this->politica->ehMaster($ctx),
            'podeCriar' => $this->politica->podeCriar($ctx),
            'podeDivulgar' => $this->politica->podeDivulgar($ctx),
            'agora' => $agora->format(\DATE_ATOM),
            'vagas' => $cartoes,
            'listas' => $this->listas(),
            'configuracao' => $ctx->configuracao->getVagas(),
            'ofertantePadrao' => $this->ofertantePadrao($ctx),
        ];
    }

    /**
     * @param list<Vaga>                        $vagas
     * @param array<int, CandidaturasDaVaga>    $resumos
     *
     * @return list<Vaga>
     */
    private function ordenar(Contexto $ctx, array $vagas, array $resumos, \DateTimeImmutable $agora): array
    {
        if ($this->politica->ehInterno($ctx)) {
            return $vagas;
        }
        $aoVivo = static fn (Vaga $v): int => (int) ($v->isOferta() && $v->situacaoEm($agora) === SituacaoVaga::Aberta);
        $compat = static fn (Vaga $v): int => $resumos[(int) $v->getId()]->compat ?? 0;
        usort($vagas, static fn (Vaga $a, Vaga $b): int => $aoVivo($b) <=> $aoVivo($a) ?: $compat($b) <=> $compat($a) ?: (int) $b->getId() <=> (int) $a->getId());

        return $vagas;
    }

    private function ultimaVisita(Contexto $ctx): ?\DateTimeImmutable
    {
        return $this->preferencias->doUsuario($ctx->usuario, PreferenciaDoUsuario::ULTIMA_VISITA_VAGAS)?->visitaAnterior();
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
     * "QUEM ESTÁ OFERECENDO": o padrão salvo desse usuário ("padrão salvo") ou, sem ele, o do cadastro
     * ("do seu cadastro", `ofOrig`, l.1184). E-mail e telefone nascem ocultos (l.1182).
     *
     * @return array<string, mixed>
     */
    private function ofertantePadrao(Contexto $ctx): array
    {
        $salvo = $ctx->previa ? null : $this->preferencias->doUsuario($ctx->usuario, PreferenciaDoUsuario::OFERTANTE_PADRAO)?->getValor();
        if (is_array($salvo) && $salvo !== []) {
            return $salvo + ['origem' => 'salvo'];
        }
        $t = $this->politica->ehInterno($ctx) || $ctx->perfil === Perfil::Convidado ? null : $ctx->tenant;

        return [
            'nome' => $t !== null ? (string) $t->getName() : ($this->politica->ehInterno($ctx) ? 'Central BlueJus' : ''),
            'resp' => trim((string) $ctx->usuario->getFullName()),
            'cidade' => (string) $t?->getCidade(),
            'uf' => (string) $t?->getEstado(),
            'email' => (string) $ctx->usuario->getEmail(),
            'tel' => '',
            'site' => '',
            'ocultos' => DadosDaVaga::OCULTOS_PADRAO,
            'origem' => 'cadastro',
        ];
    }
}
