<?php

declare(strict_types=1);

namespace App\Processo\UseCase;

use App\Djen\Entity\PublicacaoDjen;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Processo\DTO\NotaTecnicaOutput;
use App\Processo\Entity\NotaTecnica;
use App\Processo\Entity\Processo;
use App\Processo\Exception\NotaTecnicaForaDoEscopoException;
use App\Processo\Repository\NotaTecnicaRepository;
use App\Shared\Service\SanitizadorTextoRico;

/**
 * O advogado escreve uma nota técnica sobre um processo — opcionalmente pendurada numa
 * movimentação do Push Processual.
 *
 * Quem: usuário do escritório que pode editar a pasta de onde veio (ou o processo).
 * O quê: registrar, em texto rico, o que o processo/despacho significa e o que fazer.
 * Pré-condições: processo do escritório; se houver publicação, ela é do escritório E deste
 * processo (pela FK ou pelo número CNJ — a FK só existe quando a sincronização a gravou).
 * Pós-condição: a nota existe, limpa, e aparece em toda pasta que vincula o processo.
 * Erros: alvo fora do escopo (`NotaTecnicaForaDoEscopoException`), conteúdo vazio ou acima
 * de 5000 caracteres visíveis (`InvalidArgumentException`).
 */
final class CriarNotaTecnicaUseCase
{
    public function __construct(
        private readonly NotaTecnicaRepository $repository,
        private readonly SanitizadorTextoRico $sanitizador,
    ) {}

    public function executar(
        Processo $processo,
        User $autor,
        Tenant $tenant,
        string $conteudo,
        ?PublicacaoDjen $publicacao = null,
    ): NotaTecnicaOutput {
        if (!$this->mesmoTenant($processo->getTenant(), $tenant)) {
            throw new NotaTecnicaForaDoEscopoException('O processo não pertence a este escritório.');
        }

        if ($publicacao !== null) {
            if (!$this->mesmoTenant($publicacao->getTenant(), $tenant)) {
                throw new NotaTecnicaForaDoEscopoException('A publicação não pertence a este escritório.');
            }

            if (!$this->publicacaoEDoProcesso($publicacao, $processo)) {
                throw new NotaTecnicaForaDoEscopoException('A publicação não é deste processo.');
            }
        }

        // Vem do editor rico (HTML): limpo ANTES de persistir. Texto puro atravessa intacto.
        $conteudo = $this->sanitizador->limpar(trim($conteudo)) ?? '';

        // `estaVazio` porque o editor entrega `<p><br></p>` quando nada foi digitado; o limite
        // conta o texto visível, não a marcação.
        if ($this->sanitizador->estaVazio($conteudo) || $this->sanitizador->comprimentoDoTexto($conteudo) > 5000) {
            throw new \InvalidArgumentException('Conteúdo inválido: deve ter entre 1 e 5000 caracteres.');
        }

        $nota = new NotaTecnica($tenant, $processo, $autor, $conteudo, $publicacao);
        $this->repository->salvar($nota, flush: true);

        return NotaTecnicaOutput::fromEntity($nota);
    }

    private function mesmoTenant(?Tenant $a, Tenant $b): bool
    {
        if ($a === null) {
            return false;
        }

        return $a === $b || ($a->getId() !== null && $a->getId() === $b->getId());
    }

    /**
     * A FK `publicacao.processo` só é gravada na sincronização; publicação que chegou antes de o
     * processo entrar no cadastro nunca a recebe. Por isso o casamento também vale pelo número
     * CNJ (em dígitos puros nos dois lados) — o mesmo critério da aba Push da pasta.
     */
    private function publicacaoEDoProcesso(PublicacaoDjen $publicacao, Processo $processo): bool
    {
        $vinculado = $publicacao->getProcesso();
        if ($vinculado !== null) {
            return $vinculado === $processo
                || ($vinculado->getId() !== null && $vinculado->getId() === $processo->getId());
        }

        $numeroDaPublicacao = preg_replace('/\D+/', '', $publicacao->getNumeroProcesso()) ?? '';
        $numeroDoProcesso   = preg_replace('/\D+/', '', $processo->getNumeroProcesso()) ?? '';

        return $numeroDaPublicacao !== '' && $numeroDaPublicacao === $numeroDoProcesso;
    }
}
