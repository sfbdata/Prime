<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\DTO;

use App\AssinaturaEletronica\Entity\DocumentoParaAssinar;
use App\AssinaturaEletronica\Entity\Signatario;
use App\AssinaturaEletronica\Enum\CorDaLinha;
use App\AssinaturaEletronica\Enum\SituacaoDoDocumento;
use App\AssinaturaEletronica\Enum\SituacaoDoSignatario;

/**
 * O que o `Resumo` do JSON precisa de um documento (contrato da etapa 1, seção 4.1). A lista o monta direto do banco,
 * sem carregar os blocos; o detalhe o tira da entidade. As duas origens passam pela mesma apresentação.
 *
 * Etapa 3: o código de validação do selo, nulo no rascunho.
 */
final readonly class ResumoDoDocumento
{
    /**
     * @param list<array{nome: string, situacao: SituacaoDoSignatario}> $signatarios na ordem do documento
     */
    public function __construct(
        public int $id,
        public string $nome,
        public string $categoria,
        public ?string $pasta,
        public SituacaoDoDocumento $situacao,
        public \DateTimeImmutable $criadoEm,
        public ?\DateTimeImmutable $venceEm,
        public CorDaLinha $cor,
        public bool $naLixeira,
        public array $signatarios,
        public ?string $codigo = null,
    ) {
    }

    public static function deDocumento(DocumentoParaAssinar $documento): self
    {
        return new self(
            id: (int) $documento->getId(),
            nome: $documento->getNome(),
            categoria: $documento->getCategoria(),
            pasta: $documento->getPastaRotulo(),
            situacao: $documento->getSituacao(),
            criadoEm: $documento->getCriadoEm(),
            venceEm: $documento->getVenceEm(),
            cor: $documento->getCor(),
            naLixeira: $documento->estaNaLixeira(),
            signatarios: array_map(
                static fn (Signatario $s): array => ['nome' => $s->getNome(), 'situacao' => $s->getSituacao()],
                $documento->getSignatarios(),
            ),
            codigo: $documento->getCodigo(),
        );
    }

    public function contarAssinados(): int
    {
        return \count(array_filter(
            $this->signatarios,
            static fn (array $s): bool => $s['situacao'] === SituacaoDoSignatario::Assinado,
        ));
    }
}
