<?php

declare(strict_types=1);

namespace App\Pasta\UseCase;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\PastaObservacaoDetalhes;
use App\Pasta\Exception\ObservacaoDetalhesNaoEditavelException;
use App\Pasta\Service\PrazoDoComentario;
use App\Tenant\Service\AuditoriaDaEdicaoSemPrazo;
use App\Pasta\Service\ResolucaoDeMencoesDoRegistro;
use App\Shared\Service\SanitizadorTextoRico;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Edição controlada de uma observação da aba Detalhes.
 *
 * Salvaguardas: só o autor da observação pode editá-la, e apenas dentro de uma
 * janela curta após a criação (`JanelaDeEdicaoDeComentario`, 15 minutos) — o suficiente para corrigir erros de escrita.
 * Edição sem prazo (spec edicao-sem-prazo §1): o Master e o primário (com a opção do escritório) passam por cima
 * do prazo e da autoria, pelo {@see PrazoDoComentario}; o uso do poder fica na auditoria.
 *
 * @MENÇÕES (EDT-010r): a edição passa pela MESMA {@see ResolucaoDeMencoesDoRegistro} do envio e
 * avisa quem PASSOU a ser mencionado, com as regras do Registro, na mesma transação.
 *
 * Antes e Depois (PAS-045b): a edição guarda o texto anterior INTEIRO na própria observação
 * (`conteudo_anterior`, só a última versão), como o registro de Dados da pasta (PAS-045).
 */
final class EditarObservacaoDetalhesUseCase
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SanitizadorTextoRico $sanitizador,
        private readonly PrazoDoComentario $prazo,
        private readonly ResolucaoDeMencoesDoRegistro $resolucaoDeMencoes,
    ) {}

    public function podeEditar(PastaObservacaoDetalhes $observacao, User $usuario, Tenant $tenant, ?\DateTimeImmutable $agora = null): bool
    {
        $agora ??= new \DateTimeImmutable();

        if ($observacao->getTenant() !== $tenant) {
            return false;
        }

        // Autor dentro da janela, OU o poder sem prazo (Master; primário com a opção ligada).
        return $this->prazo->libera($usuario, $tenant, $observacao->pertenceAo($usuario), $observacao->getCriadaEm(), $agora);
    }

    public function executar(PastaObservacaoDetalhes $observacao, User $usuario, Tenant $tenant, string $conteudo): void
    {
        $this->exigir($observacao, $usuario, $tenant);

        // Mesma limpeza do envio: a edição é outra porta de entrada para o mesmo campo, e uma
        // porta sem sanitização anularia a da outra.
        $conteudo = $this->sanitizador->limpar(trim($conteudo)) ?? '';

        if ($this->sanitizador->estaVazio($conteudo) || $this->sanitizador->comprimentoDoTexto($conteudo) > 5000) {
            throw new \InvalidArgumentException('Conteúdo inválido: deve ter entre 1 e 5000 caracteres.');
        }

        [$conteudo, $mencionados] = $this->resolucaoDeMencoes->resolver($conteudo, $tenant);

        // O texto de antes fica guardado INTEIRO (PAS-045b, "Ver alteração" da Timeline).
        $observacao->editarConteudo($conteudo, new \DateTimeImmutable());

        $pasta = $observacao->getPasta();
        if ($mencionados === [] || $pasta === null) {
            $this->em->flush();

            return;
        }

        // Uma transação só: a edição e os avisos entram (ou não) juntos. Quem já foi avisado por
        // esta observação não recebe de novo (anti-duplicata por link).
        $this->em->wrapInTransaction(function () use ($mencionados, $observacao, $pasta, $usuario, $tenant): void {
            $this->em->flush();

            $this->resolucaoDeMencoes->notificarNaObservacao(
                $mencionados,
                $observacao,
                $pasta,
                $usuario,
                $tenant,
                $observacao->getRespostaA()?->getAutor(),
            );
        });
    }

    /** A checagem do podeEditar() e, quando foi o poder sem prazo que liberou, o rastro na auditoria (§6). */
    private function exigir(PastaObservacaoDetalhes $observacao, User $usuario, Tenant $tenant): void
    {
        if (!$this->podeEditar($observacao, $usuario, $tenant)) {
            throw new ObservacaoDetalhesNaoEditavelException('Esta observação não pode ser editada.');
        }
        $this->prazo->registrar(AuditoriaDaEdicaoSemPrazo::EDITAR, $usuario, $tenant, $observacao->pertenceAo($usuario), $observacao->getCriadaEm(), $observacao, $observacao->getAutor());
    }
}
