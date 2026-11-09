<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\UseCase;

use App\AssinaturaEletronica\Armazenamento\ChavesDeAssinaturaEletronica;
use App\AssinaturaEletronica\Enum\TipoDeLogo;
use App\AssinaturaEletronica\Repository\ConfiguracaoDaAssinaturaRepository;
use App\AssinaturaEletronica\Service\ApresentacaoDoDocumento;
use App\AssinaturaEletronica\Service\DadosPadraoDoEscritorio;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * O que a página recebe nos atributos da raiz (contrato da etapa 1, seção 4.2), menos as URLs das rotas, que o
 * controller monta: quem está logado, os dados do escritório para os modelos e as logos.
 */
final readonly class MontarPaginaDaAssinaturaUseCase
{
    public function __construct(
        private ConfiguracaoDaAssinaturaRepository $configuracoes,
        private DadosPadraoDoEscritorio $dadosPadrao,
        private ApresentacaoDoDocumento $apresentacao,
        private UrlGeneratorInterface $urls,
    ) {
    }

    /**
     * @return array{eu: array{nome: string, email: string}, padrao: array<string, string>, logos: array<string, mixed>}
     */
    public function executar(User $usuario, Tenant $tenant): array
    {
        return [
            'eu' => ['nome' => trim((string) $usuario->getFullName()), 'email' => trim((string) $usuario->getEmail())],
            'padrao' => $this->dadosPadrao->de($usuario, $tenant),
            'logos' => [
                'bluejus' => $this->apresentacao->urlDaLogoBluejus(),
                'escritorio' => $this->apresentacao->urlDaLogoDoEscritorio($tenant),
                'padrao' => $this->logoPadrao($tenant),
            ],
        ];
    }

    /** @return array{tipo: string, url: ?string} */
    private function logoPadrao(Tenant $tenant): array
    {
        $configuracao = $this->configuracoes->doEscritorio($tenant);
        $tipo = $configuracao?->getLogoPadraoTipo() ?? TipoDeLogo::Bluejus;

        $url = match ($tipo) {
            TipoDeLogo::Bluejus => $this->apresentacao->urlDaLogoBluejus(),
            TipoDeLogo::Escritorio => $this->apresentacao->urlDaLogoDoEscritorio($tenant),
            TipoDeLogo::Propria => $configuracao !== null && ChavesDeAssinaturaEletronica::logoPadrao($configuracao) !== null
                ? $this->urls->generate('assinatura_eletronica_logo_padrao')
                : null,
        };

        return ['tipo' => $tipo->value, 'url' => $url];
    }
}
