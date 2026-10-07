<?php

declare(strict_types=1);

namespace App\Processo\UseCase;

use App\Djen\Entity\PublicacaoDjen;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Service\ResolucaoDeMencoesDoRegistro;
use App\Processo\DTO\NotaTecnicaOutput;
use App\Processo\Entity\NotaTecnica;
use App\Processo\Entity\Processo;
use App\Processo\Exception\NotaTecnicaForaDoEscopoException;
use App\Processo\Repository\NotaTecnicaRepository;
use App\Shared\Service\SanitizadorTextoRico;
use Doctrine\ORM\EntityManagerInterface;

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
 *
 * @MENÇÕES (PMC-019): o conteúdo pode trazer tokens `@[Nome](user:ID)` (formato em
 * `MencoesDoRegistro`). Passam pela MESMA `ResolucaoDeMencoesDoRegistro` do Registro da pasta:
 * só colegas ativos deste escritório viram menção (o nome gravado é o do banco); o resto vira
 * texto comum. Quem recebe o aviso é decidido pela PASTA de onde a nota foi escrita (`$pasta`):
 * só quem a vê, nunca o autor, com a cota do autor. Sem pasta (nota criada direto pelo processo)
 * o token é resolvido mas ninguém é avisado — o link do aviso leva à nota na pasta, e sem pasta
 * não há para onde levar.
 */
final class CriarNotaTecnicaUseCase
{
    public function __construct(
        private readonly NotaTecnicaRepository $repository,
        private readonly SanitizadorTextoRico $sanitizador,
        private readonly EntityManagerInterface $em,
        private readonly ResolucaoDeMencoesDoRegistro $resolucaoDeMencoes,
    ) {}

    public function executar(
        Processo $processo,
        User $autor,
        Tenant $tenant,
        string $conteudo,
        ?PublicacaoDjen $publicacao = null,
        ?Pasta $pasta = null,
    ): NotaTecnicaOutput {
        if (!$this->mesmoTenant($processo->getTenant(), $tenant)) {
            throw new NotaTecnicaForaDoEscopoException('O processo não pertence a este escritório.');
        }

        // A pasta decide quem recebe o aviso de menção: tem de ser deste escritório E vincular o
        // processo — senão o aviso levaria a pasta alheia (ou a uma pasta sem esta nota).
        if ($pasta !== null && (!$this->mesmoTenant($pasta->getTenant(), $tenant) || !$pasta->temProcesso($processo))) {
            throw new NotaTecnicaForaDoEscopoException('A pasta não é deste escritório ou não vincula este processo.');
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

        [$conteudo, $mencionados] = $this->resolucaoDeMencoes->resolver($conteudo, $tenant);

        $nota = new NotaTecnica($tenant, $processo, $autor, $conteudo, $publicacao);

        if ($mencionados === [] || $pasta === null) {
            $this->repository->salvar($nota, flush: true);

            return NotaTecnicaOutput::fromEntity($nota);
        }

        // Uma transação só: a nota e os avisos entram (ou não) juntos. O link do aviso leva o id
        // da nota, que só existe depois do flush — por isso o flush acontece DENTRO da transação.
        $this->em->wrapInTransaction(function () use ($nota, $mencionados, $pasta, $autor, $tenant): void {
            $this->repository->salvar($nota, flush: true);
            $this->resolucaoDeMencoes->notificarNaNotaTecnica($mencionados, $nota, $pasta, $autor, $tenant);
        });

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
