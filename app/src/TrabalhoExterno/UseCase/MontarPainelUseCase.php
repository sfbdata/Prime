<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\UseCase;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Repository\UserTenantRepository;
use App\TrabalhoExterno\DTO\DadosDaSolicitacao;
use App\TrabalhoExterno\Entity\DemandaExterna;
use App\TrabalhoExterno\Entity\MarcacaoDoUsuario;
use App\TrabalhoExterno\Entity\MensagemDemanda;
use App\TrabalhoExterno\Enum\Disponibilidade;
use App\TrabalhoExterno\Enum\TipoItemMarcado;
use App\TrabalhoExterno\Enum\Perfil;
use App\TrabalhoExterno\Enum\Visibilidade;
use App\TrabalhoExterno\Entity\EscritorioSolicitante;
use App\TrabalhoExterno\Repository\EscritorioSolicitanteRepository;
use App\TrabalhoExterno\Repository\MarcacaoDoUsuarioRepository;
use App\TrabalhoExterno\Service\ApresentadorDeDemanda;
use App\TrabalhoExterno\Service\ContadorDeNaoLidas;
use App\TrabalhoExterno\Service\Contexto;
use App\TrabalhoExterno\Service\LocalizadorDeDemanda;
use App\TrabalhoExterno\Service\PoliticaDaDemanda;
use App\TrabalhoExterno\Service\PreviaDePerfil;

/**
 * Tudo que a tela inicial do Trabalho Externo precisa de uma vez: perfil, configuração (só o que
 * o perfil pode ver), bloqueio da aba, aviso da central, a lista de demandas visíveis com não
 * lidas, o aviso de mensagem nova ao entrar e os dados do modal "Nova solicitação".
 *
 * Consulta: não grava nada.
 */
final class MontarPainelUseCase
{
    public function __construct(
        private readonly LocalizadorDeDemanda $localizador,
        private readonly PoliticaDaDemanda $politica,
        private readonly ApresentadorDeDemanda $apresentador,
        private readonly ContadorDeNaoLidas $contador,
        private readonly UserTenantRepository $vinculos,
        private readonly PreviaDePerfil $previa,
        private readonly MarcacaoDoUsuarioRepository $marcacoes,
        private readonly EscritorioSolicitanteRepository $escritoriosSalvos,
    ) {
    }

    /** @return array<string, mixed> */
    public function executar(Contexto $ctx): array
    {
        return $this->montar($ctx, $this->localizador->visiveis($ctx));
    }

    /**
     * A mesma tela, como `$como` a veria (spec §9): só leitura, montada a partir do que o Master vê.
     *
     * @return array<string, mixed>
     */
    public function executarPrevia(Contexto $master, Perfil $como): array
    {
        return $this->montar($this->previa->contexto($master, $como), $this->previa->pares($master, $como), $master);
    }

    /**
     * `$master`: na prévia, o Master real (dono dos escritórios do "+ Nova solicitação", spec §9.1).
     *
     * @param list<array{0: DemandaExterna, 1: Contexto}> $pares
     *
     * @return array<string, mixed>
     */
    private function montar(Contexto $ctx, array $pares, ?Contexto $master = null): array
    {
        // Quem abre solicitação (decisão do dono, 16/10): o Solicitante, no próprio escritório; e o
        // Master, na visão dele ou na prévia de Solicitante, em nome de um dos escritórios dele.
        $novaSolicitacao = match (true) {
            $ctx->perfil === Perfil::Master && !$ctx->previa => $this->dadosDoModal($ctx, comSalvos: false) + ['peloMaster' => true],
            $ctx->perfil !== Perfil::Solicitante => null,
            !$ctx->previa => $this->dadosDoModal($ctx),
            $master !== null => $this->dadosDoModal($master, comSalvos: false) + ['peloMaster' => true],
            default => null,
        };
        // Pelo Master, só com algum escritório vinculado para escolher.
        if (($novaSolicitacao['peloMaster'] ?? false) && $novaSolicitacao['escritorios'] === []) {
            $novaSolicitacao = null;
        }

        $cfg = $ctx->configuracao;
        $bloqueada = $this->politica->abaBloqueada($ctx);
        $marcas = $this->marcasDoUsuario($ctx, $pares);
        $pares = array_values(array_filter($pares, static fn (array $par): bool => !($marcas[(int) $par[0]->getId()] ?? null)?->excluida()));
        $naoLidas = $this->contador->porDemanda($pares);

        return [
            'perfil' => [
                'chave' => $ctx->perfil->value,
                'rotulo' => $ctx->perfil->rotulo(),
                'papel' => $ctx->perfil->papel(),
                'nome' => trim((string) $ctx->usuario->getFullName()) ?: (string) $ctx->usuario->getEmail(),
                'interno' => $ctx->ehInterno(),
                'master' => $ctx->perfil === Perfil::Master,
                'podeSolicitar' => $novaSolicitacao !== null,
            ],
            // Só o Master real tem o seletor; na prévia, qual perfil está sendo mostrado.
            'podePrever' => $ctx->previa || $ctx->perfil === Perfil::Master,
            'previa' => $ctx->previa ? ['chave' => $ctx->perfil->value, 'rotulo' => $ctx->perfil->rotulo()] : null,
            'configuracao' => $this->configuracao($ctx),
            'bloqueio' => $bloqueada ? $this->bloqueio($ctx) : null,
            'aviso' => $this->aviso($ctx),
            'envioBloqueado' => $cfg->envioBloqueado(),
            'hoje' => (new \DateTimeImmutable('today'))->format('Y-m-d'),
            'demandas' => array_map(
                fn (array $par): array => $this->apresentador->linha($par[1], $par[0], $naoLidas[(int) $par[0]->getId()] ?? 0, $marcas[(int) $par[0]->getId()] ?? null),
                $pares,
            ),
            'avisoDeMensagem' => $ctx->previa ? null : $this->avisoDeMensagem($pares, $naoLidas),
            'novaSolicitacao' => $novaSolicitacao,
        ];
    }

    /**
     * Estrela, lixeira, importância e "não lida" de quem está logado (spec VS 18.10.26 §2). Na
     * prévia não há: as marcações são pessoais e a prévia mostra a tela de outra pessoa.
     *
     * @param list<array{0: DemandaExterna, 1: Contexto}> $pares
     *
     * @return array<int, MarcacaoDoUsuario>
     */
    private function marcasDoUsuario(Contexto $ctx, array $pares): array
    {
        if ($ctx->previa) {
            return [];
        }

        return $this->marcacoes->doUsuario($ctx->usuario, TipoItemMarcado::Demanda, array_map(static fn (array $par): int => (int) $par[0]->getId(), $pares));
    }

    /** @return array<string, mixed> */
    private function configuracao(Contexto $ctx): array
    {
        $cfg = $ctx->configuracao;
        $publica = [
            'visibilidade' => $cfg->getVisibilidade()->value,
            'disponibilidade' => $cfg->getDisponibilidade()->value,
        ];
        if ($ctx->perfil !== Perfil::Master) {
            return $publica;
        }

        return $publica + [
            'motivo' => (string) $cfg->getMotivo(),
            'bloquearSuspenso' => $cfg->isBloquearSuspenso(),
            'equipe' => $cfg->getEquipe(),
            'privacidade' => $cfg->getPrivacidade(),
            'sede' => $cfg->getSede()?->getName(),
        ];
    }

    /** @return array{titulo: string, texto: string} */
    private function bloqueio(Contexto $ctx): array
    {
        $cfg = $ctx->configuracao;
        $oculta = $cfg->getVisibilidade() === Visibilidade::Oculta;
        $texto = match (true) {
            $oculta => 'O serviço foi suspenso pelo administrador do BlueJus.',
            $ctx->perfil === Perfil::Equipe => 'O Master ainda não liberou esta aba para a equipe.',
            default => 'Esta área está disponível apenas para a equipe interna no momento.',
        };
        if ($oculta && $cfg->getMotivo() !== null) {
            $texto .= ' Motivo: ' . $cfg->getMotivo();
        }

        return [
            'titulo' => $oculta ? 'Trabalho Externo temporariamente indisponível' : 'Acesso restrito',
            'texto' => $texto,
        ];
    }

    private function aviso(Contexto $ctx): string
    {
        $disp = $ctx->configuracao->getDisponibilidade();

        return match (true) {
            $disp === Disponibilidade::Suspensa && $ctx->ehInterno() => 'A central está suspensa: novas solicitações ficam só como rascunho. Demandas já aceitas continuam normalmente.',
            $disp === Disponibilidade::Suspensa => 'A central está temporariamente suspensa. Você pode salvar a solicitação como rascunho e enviar quando ela for reativada.',
            $disp === Disponibilidade::ComRestricoes && $ctx->perfil === Perfil::Solicitante => 'A central está disponível com restrições: o prazo de análise pode ser maior.',
            default => '',
        };
    }

    /**
     * O aviso no canto ao entrar: a mensagem mais recente, de outra pessoa, na primeira demanda
     * com não lidas (`setPerfil` do desenho).
     *
     * @param list<array{0: DemandaExterna, 1: Contexto}> $pares
     * @param array<int, int>                             $naoLidas
     *
     * @return array{codigo: string, titulo: string, texto: string, hora: string}|null
     */
    private function avisoDeMensagem(array $pares, array $naoLidas): ?array
    {
        foreach ($pares as [$d, $ctx]) {
            if (($naoLidas[(int) $d->getId()] ?? 0) === 0) {
                continue;
            }
            $deOutros = array_filter(
                $d->getMensagens(),
                // A mesma regra do chat: o aviso não pode mostrar o que a conversa esconde.
                fn (MensagemDemanda $m): bool => $m->getAutor()?->getId() !== $ctx->usuario->getId()
                    && $this->politica->mensagemVisivel($ctx, $d, $m),
            );
            $ultima = end($deOutros);
            if ($ultima === false) {
                continue;
            }

            return [
                'codigo' => $d->getCodigo(),
                'titulo' => $this->politica->nomeVisivel($ctx, $ultima->getNomeAutor(), $ultima->getPerfilAutor()) . ' · ' . $d->getCodigo(),
                'texto' => mb_substr($ultima->getTexto(), 0, 110),
                'hora' => $ultima->getEm()->format('H:i'),
            ];
        }

        return null;
    }

    /**
     * Pré-preenchimento do bloco SOLICITANTE ("Preenchido do seu perfil") e os escritórios da conta.
     * O escritório atual vem primeiro: é ele o dono da demanda.
     *
     * @return array<string, mixed>
     */
    private function dadosDoModal(Contexto $ctx, bool $comSalvos = true): array
    {
        $u = $ctx->usuario;
        $vinculos = $this->vinculos->findActiveByUser($u);
        usort($vinculos, static fn (UserTenant $a, UserTenant $b): int => (int) ($b->getTenant()?->getId() === $ctx->tenant?->getId()) <=> (int) ($a->getTenant()?->getId() === $ctx->tenant?->getId()));

        $atual = null;
        $escritorios = [];
        foreach ($vinculos as $v) {
            $t = $v->getTenant();
            if ($t === null) {
                continue;
            }
            if ($t->getId() === $ctx->tenant?->getId()) {
                $atual = $v;
            }
            $escritorios[] = [
                'id' => $t->getId(),
                'nome' => (string) $t->getName(),
                'email' => (string) $u->getEmail(),
                'telefone' => '',
                'uf' => (string) $t->getEstado(),
                'cidade' => (string) $t->getCidade(),
            ];
        }

        if ($comSalvos) {
            array_push($escritorios, ...$this->escritoriosGuardados($u));
        }

        $lotacao = (string) $atual?->getLotacao()?->getNome();
        preg_match('/^(.+?)\/([A-Z]{2})$/', $lotacao, $m);

        return [
            'usuario' => [
                'nome' => trim((string) $u->getFullName()),
                'email' => (string) $u->getEmail(),
                'oab' => (string) $u->getOabNumero(),
                'oabUf' => (string) ($u->getOabUf() ?: 'DF'),
                'cidadeLotacao' => $m[1] ?? '',
                'ufLotacao' => $m[2] ?? '',
            ],
            'escritorios' => $escritorios,
            'tipos' => DadosDaSolicitacao::TIPOS,
            'ufs' => DadosDaSolicitacao::UFS,
            'prioridades' => DadosDaSolicitacao::PRIORIDADES,
            'prazoPadrao' => (new \DateTimeImmutable('today +7 days'))->format('Y-m-d'),
        ];
    }

    /**
     * Os escritórios que o usuário guardou, depois dos da conta, com `salvo` e id próprio (`s<id>`). O Master abre
     * em nome de um escritório da plataforma, então para ele não há guardado para escolher.
     *
     * @return list<array<string, mixed>>
     */
    private function escritoriosGuardados(User $usuario): array
    {
        return array_map(static fn (EscritorioSolicitante $e): array => $e->paraPainel(), $this->escritoriosSalvos->doUsuario($usuario));
    }
}
