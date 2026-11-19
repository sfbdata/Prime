<?php

declare(strict_types=1);

namespace App\Contribua\UseCase;

use App\Contribua\DTO\JanelaDaCampanhaView;
use App\Contribua\DTO\ParticipacaoDaCampanhaView;
use App\Contribua\DTO\Solicitante;
use App\Contribua\Entity\CampanhaParticipacao;
use App\Contribua\Enum\EstadoDaCampanha;
use App\Contribua\Enum\SituacaoDaParticipacao;
use App\Contribua\Repository\CampanhaDevolucaoRepository;
use App\Contribua\Repository\CampanhaParticipacaoRepository;
use App\Contribua\Service\CampanhaVigente;
use App\Contribua\Service\CodigoPixEstatico;
use App\Contribua\Service\FormatoDaCampanha;
use App\Contribua\Service\RegrasDaCampanha;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * Os dados da janela da campanha (a que abre pela aba, pela faixa, pelo FAQ e por `#black`). Null sem campanha e
 * para o escritório criado há mais de 5 dias, salvo o Master (prévia) e quem já se inscreveu.
 */
final class MontarJanelaDaCampanhaUseCase
{
    private const CSRF = 'contribua';

    public function __construct(
        private readonly CampanhaVigente $vigente,
        private readonly CampanhaParticipacaoRepository $participacoes,
        private readonly CampanhaDevolucaoRepository $devolucoes,
        private readonly MontarCampanhaViewUseCase $montarView,
        private readonly RegrasDaCampanha $regras,
        private readonly CsrfTokenManagerInterface $csrf,
        private readonly UrlGeneratorInterface $urls,
    ) {
    }

    public function executar(Solicitante $quem): ?JanelaDaCampanhaView
    {
        $campanha = $this->vigente->campanha();
        if ($campanha === null) {
            return null;
        }
        $estado = $this->vigente->estado($campanha);
        $participacao = $this->participacoes->daPessoa($campanha, $quem->usuario);
        if (!$quem->ehMaster && $participacao === null && !$this->vigente->contaNova($quem->escritorio)) {
            return null;
        }

        return new JanelaDaCampanhaView(
            campanha: $this->montarView->montar($campanha, $estado, $quem->ehMaster, $participacao !== null),
            regras: $this->regras->gerar($campanha),
            beneficios: $campanha->getBeneficios(),
            inscritos: $campanha->isMostrarContador() && $estado !== EstadoDaCampanha::Desligada ? $this->participacoes->contarPagas($campanha) : null,
            vagas: $campanha->getVagas(),
            nomeSugerido: (string) $quem->usuario->getFullName(),
            csrf: $this->csrf->getToken(self::CSRF)->getValue(),
            urls: [
                'inscrever' => $this->urls->generate('contribua_campanha_inscrever'),
                'devolucao' => $this->urls->generate('contribua_campanha_devolucao'),
            ],
            urlConfigurar: $quem->ehMaster ? $this->urls->generate('contribua_index') . '#campanha' : null,
            participacao: $participacao === null ? null : $this->daParticipacao($participacao),
        );
    }

    private function daParticipacao(CampanhaParticipacao $p): ParticipacaoDaCampanhaView
    {
        $hoje = $this->vigente->hoje();
        $aguardando = $p->getSituacao() === SituacaoDaParticipacao::AguardandoPagamento;
        $devolucao = $p->getSituacao() === SituacaoDaParticipacao::Ativa ? null : $this->devolucoes->daParticipacao($p);

        return new ParticipacaoDaCampanhaView(
            situacao: $p->getSituacao()->value,
            desde: FormatoDaCampanha::dataDoInstante($p->getPagoEm() ?? $p->getCriadaEm()),
            diasRestantes: $p->diasRestantes($hoje),
            podeDevolver: $p->podeDevolver($hoje),
            valorDevolucao: FormatoDaCampanha::reais($p->valorDaDevolucaoCentavos()),
            pedidaEm: $devolucao === null ? null : FormatoDaCampanha::dataDoInstante($devolucao->getPedidaEm()),
            pixCodigo: $aguardando ? CodigoPixEstatico::gerar($p->getTxid() ?? 'BJB' . $p->getId(), $p->getValorCentavos()) : null,
            preco: FormatoDaCampanha::reais($p->getValorCentavos()),
        );
    }
}
