<?php

declare(strict_types=1);

namespace App\Pasta\UseCase;

use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaDocumento;
use App\Pasta\Exception\PastaDeOutroEscritorioException;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Troca o nome de um documento de contrato da aba Financeiro da pasta.
 *
 * PRÉ (de quem chama): a pessoa pode editar a pasta. Aqui se confere a posse: a pasta é do escritório e o documento é
 * um contrato desta pasta e deste escritório. Nome vazio ou com mais de 255 caracteres é recusado.
 */
final class RenomearDocumentoDoFinanceiroUseCase
{
    public const TAMANHO_MAXIMO = 255;

    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * O nome já limpo, ou a recusa da regra. Só confere, não grava (o conector usa na proposta).
     *
     * @throws \InvalidArgumentException
     */
    public static function normalizar(string $titulo): string
    {
        $titulo = trim($titulo);
        if ($titulo === '') {
            throw new \InvalidArgumentException('O nome não pode ser vazio.');
        }
        if (mb_strlen($titulo) > self::TAMANHO_MAXIMO) {
            throw new \InvalidArgumentException(sprintf('Nome muito longo (máx. %d caracteres).', self::TAMANHO_MAXIMO));
        }

        return $titulo;
    }

    /**
     * @return string o nome gravado
     *
     * @throws PastaDeOutroEscritorioException
     * @throws \DomainException               o documento não é um contrato desta pasta
     * @throws \InvalidArgumentException      nome vazio ou longo demais
     */
    public function executar(Pasta $pasta, PastaDocumento $documento, string $titulo, Tenant $tenant): string
    {
        $escritorio = $pasta->getTenant()?->getId();
        if ($escritorio === null || $escritorio !== $tenant->getId()) {
            throw new PastaDeOutroEscritorioException('Pasta não pertence ao escritório.');
        }
        if (
            $documento->getTenant()?->getId() !== $escritorio
            || $documento->getPasta()?->getId() !== $pasta->getId()
            || $documento->getCategoria() !== PastaDocumento::CATEGORIA_CONTRATO
        ) {
            throw new \DomainException('Documento não encontrado nesta pasta.');
        }

        $documento->setTitulo(self::normalizar($titulo));
        $this->em->flush();

        return $documento->getTitulo();
    }
}
