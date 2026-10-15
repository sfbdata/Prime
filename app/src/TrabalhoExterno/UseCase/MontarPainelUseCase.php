<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\UseCase;

use App\Entity\Auth\UserTenant;
use App\Repository\UserTenantRepository;
use App\TrabalhoExterno\DTO\DadosDaSolicitacao;
use App\TrabalhoExterno\Entity\DemandaExterna;
use App\TrabalhoExterno\Entity\MensagemDemanda;
use App\TrabalhoExterno\Enum\Disponibilidade;
use App\TrabalhoExterno\Enum\Perfil;
use App\TrabalhoExterno\Enum\TipoMensagem;
use App\TrabalhoExterno\Enum\Visibilidade;
use App\TrabalhoExterno\Repository\MensagemDemandaRepository;
use App\TrabalhoExterno\Service\ApresentadorDeDemanda;
use App\TrabalhoExterno\Service\Contexto;
use App\TrabalhoExterno\Service\LocalizadorDeDemanda;
use App\TrabalhoExterno\Service\PoliticaDaDemanda;

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
        private readonly MensagemDemandaRepository $mensagens,
        private readonly UserTenantRepository $vinculos,
    ) {
    }

    /** @return array<string, mixed> */
    public function executar(Contexto $ctx): array
    {
        $cfg = $ctx->configuracao;
        $bloqueada = $this->politica->abaBloqueada($ctx);
        $demandas = $this->localizador->visiveis($ctx);
        $naoLidas = $this->mensagens->naoLidasPorDemanda($demandas, $ctx->usuario, $ctx->ehInterno());

        return [
            'perfil' => [
                'chave' => $ctx->perfil->value,
                'rotulo' => $ctx->perfil->rotulo(),
                'papel' => $ctx->perfil->papel(),
                'nome' => trim((string) $ctx->usuario->getFullName()) ?: (string) $ctx->usuario->getEmail(),
                'interno' => $ctx->ehInterno(),
                'master' => $ctx->perfil === Perfil::Master,
                'podeSolicitar' => $ctx->perfil === Perfil::Solicitante,
            ],
            'configuracao' => $this->configuracao($ctx),
            'bloqueio' => $bloqueada ? $this->bloqueio($ctx) : null,
            'aviso' => $this->aviso($ctx),
            'envioBloqueado' => $cfg->envioBloqueado(),
            'hoje' => (new \DateTimeImmutable('today'))->format('Y-m-d'),
            'demandas' => array_map(
                fn (DemandaExterna $d): array => $this->apresentador->linha($ctx, $d, $naoLidas[(int) $d->getId()] ?? 0),
                $demandas,
            ),
            'avisoDeMensagem' => $this->avisoDeMensagem($ctx, $demandas, $naoLidas),
            'novaSolicitacao' => $ctx->perfil === Perfil::Solicitante ? $this->dadosDoModal($ctx) : null,
        ];
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
     * @param list<DemandaExterna> $demandas
     * @param array<int, int>      $naoLidas
     *
     * @return array{codigo: string, titulo: string, texto: string, hora: string}|null
     */
    private function avisoDeMensagem(Contexto $ctx, array $demandas, array $naoLidas): ?array
    {
        foreach ($demandas as $d) {
            if (($naoLidas[(int) $d->getId()] ?? 0) === 0) {
                continue;
            }
            $deOutros = array_filter(
                $d->getMensagens(),
                static fn (MensagemDemanda $m): bool => $m->getAutor()?->getId() !== $ctx->usuario->getId()
                    && ($ctx->ehInterno() || $m->getTipo() !== TipoMensagem::Interna),
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
    private function dadosDoModal(Contexto $ctx): array
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
}
