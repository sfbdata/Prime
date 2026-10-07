<?php

declare(strict_types=1);

namespace App\Pasta\UseCase;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\PastaMensagem;
use App\Pasta\Exception\MensagemPastaNaoEditavelException;
use App\Pasta\Service\JanelaDeEdicaoDeComentario;
use App\Pasta\Service\ResolucaoDeMencoesDoRegistro;
use App\Shared\Service\SanitizadorTextoRico;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Edição controlada de uma mensagem do chat da pasta.
 *
 * Salvaguardas: só o autor da mensagem pode editá-la, e apenas dentro de uma
 * janela curta após a criação (`JanelaDeEdicaoDeComentario`, 15 minutos) — o suficiente para corrigir erros de escrita.
 *
 * @MENÇÕES (item 20b): a edição passa pela MESMA {@see ResolucaoDeMencoesDoRegistro} do envio —
 * só valem ids de colegas ATIVOS deste escritório (o nome gravado é o do banco); token forjado,
 * de outro escritório ou além do teto vira texto comum. Decisão: editar NOTIFICA quem passou a
 * ser mencionado, com as mesmas regras do envio (VIEW na pasta, nunca o autor, precedência do
 * autor respondido numa resposta), na mesma transação da edição. Quem já foi avisado por esta
 * mensagem (no envio ou numa edição anterior) não recebe de novo: a anti-duplicata é por
 * destinatário + escritório + tipo + link, e o link é o da mensagem.
 */
final class EditarMensagemPastaUseCase
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SanitizadorTextoRico $sanitizador,
        private readonly JanelaDeEdicaoDeComentario $janela,
        private readonly ResolucaoDeMencoesDoRegistro $resolucaoDeMencoes,
    ) {}

    public function podeEditar(PastaMensagem $mensagem, User $usuario, Tenant $tenant, ?\DateTimeImmutable $agora = null): bool
    {
        $agora ??= new \DateTimeImmutable();

        if ($mensagem->getTenant() !== $tenant) {
            return false;
        }

        if (!$mensagem->pertenceAo($usuario)) {
            return false;
        }

        return $this->janela->estaAberta($mensagem->getCriadaEm(), $agora);
    }

    public function executar(PastaMensagem $mensagem, User $usuario, Tenant $tenant, string $conteudo): void
    {
        if (!$this->podeEditar($mensagem, $usuario, $tenant)) {
            throw new MensagemPastaNaoEditavelException('Esta mensagem não pode ser editada.');
        }

        // Vem do editor rico (HTML): limpo ANTES de persistir. Texto puro atravessa intacto.
        $conteudo = $this->sanitizador->limpar(trim($conteudo)) ?? '';

        // `estaVazio` porque o editor entrega `<p><br></p>` quando nada foi digitado; o limite
        // conta o texto visível, não a marcação.
        if ($this->sanitizador->estaVazio($conteudo) || $this->sanitizador->comprimentoDoTexto($conteudo) > 5000) {
            throw new \InvalidArgumentException('Conteúdo inválido: deve ter entre 1 e 5000 caracteres.');
        }

        [$conteudo, $mencionados] = $this->resolucaoDeMencoes->resolver($conteudo, $tenant);

        $mensagem->setConteudo($conteudo);
        $mensagem->setEditadaEm(new \DateTimeImmutable());

        $pasta = $mensagem->getPasta();
        if ($mencionados === [] || $pasta === null) {
            $this->em->flush();

            return;
        }

        // Uma transação só: a edição e as notificações entram (ou não) juntas.
        $this->em->wrapInTransaction(function () use ($mencionados, $mensagem, $pasta, $usuario, $tenant): void {
            $this->em->flush();

            $this->resolucaoDeMencoes->notificar(
                $mencionados,
                $mensagem,
                $pasta,
                $usuario,
                $tenant,
                $mensagem->getRespostaA()?->getAutor(),
            );
        });
    }
}
