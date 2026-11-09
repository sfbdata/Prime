<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\Service;

use App\AssinaturaEletronica\Entity\Signatario;
use App\AssinaturaEletronica\Exception\AcaoRecusada;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * O segredo próprio da Assinatura (`ASSINATURA_SEGREDO`, nunca o `APP_SECRET`) e o que sai dele (contrato da etapa 2,
 * seção 3):
 *
 * - o TOKEN do link de cada signatário é derivado, não sorteado: `hex(HMAC-SHA256(segredo, assinar|v1|token|{id do
 *   signatário}|{enviado_em ISO 8601 UTC}))`. O banco guarda só o sha256 dele (`token_hash`); o servidor recalcula o
 *   token para o "Copiar link" e o e-mail. O token nunca é gravado, logado nem posto em fila;
 * - o CÓDIGO de 6 dígitos vai por e-mail e só o HMAC dele fica em `codigo_hash`, conferido com `hash_equals`.
 *
 * Segredo vazio ou curto: o envio, o link e o código recusam com "Assinatura eletrônica sem chave configurada." A
 * página pública abre assim mesmo (só precisa do sha256). Trocar o segredo derruba os links abertos: a versão vai em
 * `dados.chave` de cada evidência para uma troca futura acrescentar a versão nova sem apagar a antiga.
 */
final readonly class ChaveDaAssinatura
{
    public const VERSAO = 1;
    public const TAMANHO_MINIMO = 32;

    public function __construct(
        #[Autowire(param: 'assinatura.segredo')]
        #[\SensitiveParameter]
        private string $segredo,
    ) {
    }

    public function configurada(): bool
    {
        return \strlen($this->segredo) >= self::TAMANHO_MINIMO;
    }

    /** @throws AcaoRecusada sem segredo configurado */
    public function exigirConfigurada(): void
    {
        if (!$this->configurada()) {
            throw new AcaoRecusada('assinatura_eletronica.erro.sem_chave');
        }
    }

    /**
     * O token do link do signatário (64 caracteres hexadecimais). Exige o documento enviado e o signatário gravado.
     *
     * @throws AcaoRecusada sem segredo configurado
     */
    public function token(Signatario $signatario): string
    {
        $this->exigirConfigurada();
        $enviadoEm = $signatario->getDocumento()->getEnviadoEm() ?? throw new \LogicException('Token de documento não enviado.');
        $id = $signatario->getId() ?? throw new \LogicException('Token de signatário não gravado.');
        $utc = $enviadoEm->setTimezone(new \DateTimeZone('UTC'))->format(\DATE_ATOM);

        return hash_hmac('sha256', sprintf('assinar|v%d|token|%d|%s', self::VERSAO, $id, $utc), $this->segredo);
    }

    /** O que o banco guarda do token: sha256 puro (o token já tem 256 bits do HMAC). */
    public static function hashDoToken(#[\SensitiveParameter] string $token): string
    {
        return hash('sha256', $token);
    }

    /** @throws AcaoRecusada sem segredo configurado */
    public function hashDoCodigo(Signatario $signatario, #[\SensitiveParameter] string $codigo): string
    {
        $this->exigirConfigurada();
        $id = $signatario->getId() ?? throw new \LogicException('Código de signatário não gravado.');

        return hash_hmac('sha256', sprintf('assinar|v%d|codigo|%d|%s', self::VERSAO, $id, $codigo), $this->segredo);
    }

    /**
     * Confere o código digitado com o guardado, em tempo constante. Sem código guardado, falso.
     *
     * @throws AcaoRecusada sem segredo configurado
     */
    public function codigoConfere(Signatario $signatario, #[\SensitiveParameter] string $codigo): bool
    {
        $guardado = $signatario->getCodigoHash();

        return $guardado !== null && hash_equals($guardado, $this->hashDoCodigo($signatario, $codigo));
    }

    /** Código novo de 6 dígitos (100000 a 999999, como o desenho). */
    public static function novoCodigo(): string
    {
        return (string) random_int(100000, 999999);
    }
}
