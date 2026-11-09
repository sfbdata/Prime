<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\UseCase;

use App\AssinaturaEletronica\Armazenamento\ChavesDeAssinaturaEletronica;
use App\AssinaturaEletronica\DTO\ArquivoParaEntregar;
use App\AssinaturaEletronica\Entity\Signatario;
use App\AssinaturaEletronica\Enum\EstadoDaPagina;
use App\AssinaturaEletronica\Enum\TipoDeLogo;
use App\AssinaturaEletronica\Exception\LinkInvalido;
use App\AssinaturaEletronica\Service\SignatarioDoLink;
use App\Tenant\Armazenamento\ChavesDeTenant;
use Psr\Clock\ClockInterface;

/**
 * Os arquivos da folha na tela pública (contrato da etapa 2, seção 5.2): o PDF enviado, a logo (a própria do documento
 * ou a de Meu Escritório; a da BlueJus é arquivo estático) e a rubrica de quem já assinou. Só enquanto o signatário do
 * link pode assinar (estado `pronto`, o único em que a página mostra a folha); fora disso, o mesmo 404 do link inválido.
 * A chave sai sempre do registro achado pelo token, nunca de parâmetro da requisição.
 */
final readonly class ObterArquivoDoSignatarioUseCase
{
    public function __construct(
        private SignatarioDoLink $link,
        private ClockInterface $relogio,
    ) {
    }

    /** @throws LinkInvalido */
    public function pdf(#[\SensitiveParameter] string $token): ArquivoParaEntregar
    {
        $documento = $this->pronto($token)->getDocumento();
        $chave = ChavesDeAssinaturaEletronica::arquivo($documento) ?? throw new LinkInvalido();

        return new ArquivoParaEntregar($chave, (string) $documento->getArquivoEnviadoNome());
    }

    /** @throws LinkInvalido */
    public function logo(#[\SensitiveParameter] string $token): ArquivoParaEntregar
    {
        $documento = $this->pronto($token)->getDocumento();
        $chave = match ($documento->getLogoTipo()) {
            TipoDeLogo::Propria => ChavesDeAssinaturaEletronica::logo($documento),
            TipoDeLogo::Escritorio => ChavesDeTenant::logo($documento->getTenant()),
            TipoDeLogo::Bluejus => null,
        };

        return new ArquivoParaEntregar($chave ?? throw new LinkInvalido(), 'logo');
    }

    /** @throws LinkInvalido */
    public function rubrica(#[\SensitiveParameter] string $token, int $ordem): ArquivoParaEntregar
    {
        foreach ($this->pronto($token)->getDocumento()->getSignatarios() as $signatario) {
            if ($signatario->getOrdem() === $ordem) {
                return new ArquivoParaEntregar(ChavesDeAssinaturaEletronica::rubrica($signatario) ?? throw new LinkInvalido(), 'rubrica.png');
            }
        }

        throw new LinkInvalido();
    }

    /** @throws LinkInvalido */
    private function pronto(string $token): Signatario
    {
        $signatario = $this->link->localizar($token);
        $agora = \DateTimeImmutable::createFromInterface($this->relogio->now());
        if ($signatario->getDocumento()->estadoPara($signatario, $agora) !== EstadoDaPagina::Pronto) {
            throw new LinkInvalido();
        }

        return $signatario;
    }
}
