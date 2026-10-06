<?php

declare(strict_types=1);

namespace App\Pasta\UseCase;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\DTO\EditarDocumentoDaPastaInput;
use App\Pasta\DTO\ExploradorDeDocumentosOutput;
use App\Pasta\Entity\PastaDocumento;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Edita os metadados de um documento da pasta: nome (sem a extensão), categoria, número e
 * descrição — o que o modal "Editar" da aba Documentos envia (D3, DOC-54).
 *
 * Quem dispara é um usuário com permissão de EDITAR a pasta (a rota checa; aqui só o tenant é
 * reconferido). O que ele quer: corrigir como o arquivo aparece na lista, sem reenviar nada.
 *
 * Regras, herdadas do `PastaController::editDocumento` que isto substitui — nenhuma mudou:
 *  - a extensão é preservada: o usuário edita só a base do nome, e o `.pdf` volta sozinho;
 *  - categoria fora da lista da aba (ou vazia) mantém a atual — nunca vira "DEMAIS" por engano;
 *  - descrição e número em branco viram NULL (o formulário manda a chave vazia, não a omite);
 *  - `nomeBase` em branco mantém o nome.
 *
 * O que é novo: `modificado_em` recebe o instante da edição — só quando algo mudou de fato.
 * Submeter o modal sem alterar nada não "modifica" o documento. Renomear NÃO propaga ao Drive
 * (hoje só pasta propaga; não é regressão — registrado na spec).
 */
final class EditarDocumentoDaPastaUseCase
{
    private const TAMANHO_MAXIMO_DO_NOME = 255;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ClockInterface $clock,
    ) {
    }

    public function executar(PastaDocumento $documento, User $autor, Tenant $tenant, EditarDocumentoDaPastaInput $input): void
    {
        if ($documento->getTenant() !== $tenant) {
            throw new AccessDeniedException('Documento não pertence ao tenant do usuário.');
        }

        $categoriaRaw = strtoupper(trim((string) $input->categoria));
        $categoria    = array_key_exists($categoriaRaw, ExploradorDeDocumentosOutput::CATEGORIAS)
            ? $categoriaRaw
            : $documento->getCategoria();

        $descricao = trim((string) $input->descricao);
        $numero    = trim((string) $input->numero);
        $nomeBase  = trim((string) $input->nomeBase);

        $nome = $documento->getNomeOriginal();
        if ($nomeBase !== '') {
            $extensao = pathinfo($documento->getNomeOriginal(), PATHINFO_EXTENSION);
            $nome     = $nomeBase . ($extensao !== '' ? '.' . $extensao : '');

            if (mb_strlen($nome) > self::TAMANHO_MAXIMO_DO_NOME) {
                throw new \InvalidArgumentException(sprintf(
                    'O nome do arquivo deve ter no máximo %d caracteres (com a extensão).',
                    self::TAMANHO_MAXIMO_DO_NOME,
                ));
            }
        }

        $antes = $this->estado($documento);

        $documento->setCategoria($categoria);
        $documento->setDescricao($descricao !== '' ? $descricao : null);
        $documento->setNumero($numero !== '' ? $numero : null);
        $documento->setNomeOriginal($nome);

        if ($this->estado($documento) !== $antes) {
            $documento->marcarModificadoEm($this->clock->now());
        }

        $this->em->flush();
    }

    /** @return array{string, ?string, ?string, string} */
    private function estado(PastaDocumento $documento): array
    {
        return [
            $documento->getCategoria(),
            $documento->getDescricao(),
            $documento->getNumero(),
            $documento->getNomeOriginal(),
        ];
    }
}
