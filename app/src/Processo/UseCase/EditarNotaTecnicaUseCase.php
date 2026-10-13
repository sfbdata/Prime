<?php

declare(strict_types=1);

namespace App\Processo\UseCase;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Service\JanelaDeEdicaoDeComentario;
use App\Pasta\Service\ResolucaoDeMencoesDoRegistro;
use App\Processo\DTO\NotaTecnicaOutput;
use App\Processo\Entity\NotaTecnica;
use App\Processo\Entity\NotaTecnicaVersao;
use App\Processo\Exception\NotaTecnicaForaDoEscopoException;
use App\Processo\Exception\NotaTecnicaNaoEditavelException;
use App\Processo\Repository\NotaTecnicaRepository;
use App\Processo\Repository\NotaTecnicaVersaoRepository;
use App\Shared\Service\SanitizadorTextoRico;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Edição controlada de uma nota técnica.
 *
 * Salvaguardas: só o autor pode editá-la, e apenas dentro da janela curta após a criação
 * (`JanelaDeEdicaoDeComentario`, 15 minutos — o mesmo serviço das observações da pasta, para a
 * regra existir num lugar só). Decisão do dono (2026-10-05).
 *
 * @MENÇÕES (PMC-019): a edição passa pela MESMA `ResolucaoDeMencoesDoRegistro` da criação — token
 * forjado, de outro escritório ou de ex-colaborador vira texto comum. Editar avisa só quem
 * PASSOU a ser mencionado: a anti-duplicata é por destinatário + escritório + tipo + link, e o
 * link é o da nota. Como na criação, sem pasta de contexto ninguém é avisado.
 *
 * Versões (PMC-020): a edição que MUDA o texto guarda o de antes, inteiro, em `nota_tecnica_versao`
 * — no mesmo flush da edição (as duas entram ou nenhuma). Salvar sem mudar não cria versão. Só se
 * grava: o desenho não mostra as versões.
 */
final class EditarNotaTecnicaUseCase
{
    public function __construct(
        private readonly NotaTecnicaRepository $repository,
        private readonly SanitizadorTextoRico $sanitizador,
        private readonly JanelaDeEdicaoDeComentario $janela,
        private readonly EntityManagerInterface $em,
        private readonly ResolucaoDeMencoesDoRegistro $resolucaoDeMencoes,
        private readonly NotaTecnicaVersaoRepository $versaoRepository,
    ) {}

    public function podeEditar(NotaTecnica $nota, User $usuario, Tenant $tenant, ?\DateTimeImmutable $agora = null): bool
    {
        $agora ??= new \DateTimeImmutable();

        if ($nota->getTenant() !== $tenant) {
            return false;
        }

        if (!$nota->pertenceAo($usuario)) {
            return false;
        }

        return $this->janela->estaAberta($nota->getCriadaEm(), $agora);
    }

    public function executar(NotaTecnica $nota, User $usuario, Tenant $tenant, string $conteudo, ?Pasta $pasta = null): NotaTecnicaOutput
    {
        if (!$this->podeEditar($nota, $usuario, $tenant)) {
            throw new NotaTecnicaNaoEditavelException('Esta nota técnica não pode ser editada.');
        }

        // Mesma prova da criação: a pasta que decide os avisos é deste escritório e vincula o processo.
        if ($pasta !== null && (!self::mesmoTenant($pasta->getTenant(), $tenant) || !$pasta->temProcesso($nota->getProcesso()))) {
            throw new NotaTecnicaForaDoEscopoException('A pasta não é deste escritório ou não vincula este processo.');
        }

        // Mesma limpeza da criação: a edição é outra porta de entrada para o mesmo campo, e uma
        // porta sem sanitização anularia a da outra.
        $conteudo = $this->sanitizador->limpar(trim($conteudo)) ?? '';

        if ($this->sanitizador->estaVazio($conteudo) || $this->sanitizador->comprimentoDoTexto($conteudo) > 5000) {
            throw new \InvalidArgumentException('Conteúdo inválido: deve ter entre 1 e 5000 caracteres.');
        }

        [$conteudo, $mencionados] = $this->resolucaoDeMencoes->resolver($conteudo, $tenant);

        if ($conteudo !== $nota->getConteudo()) {
            $this->versaoRepository->adicionar(NotaTecnicaVersao::daNotaAntesDeEditar($nota, $tenant));
        }
        $nota->editar($conteudo);

        if ($mencionados === [] || $pasta === null) {
            $this->repository->salvar($nota, flush: true);

            return NotaTecnicaOutput::fromEntity($nota);
        }

        // Uma transação só: a edição e os avisos entram (ou não) juntos.
        $this->em->wrapInTransaction(function () use ($nota, $mencionados, $pasta, $usuario, $tenant): void {
            $this->repository->salvar($nota, flush: true);
            $this->resolucaoDeMencoes->notificarNaNotaTecnica($mencionados, $nota, $pasta, $usuario, $tenant);
        });

        return NotaTecnicaOutput::fromEntity($nota);
    }

    private static function mesmoTenant(?Tenant $a, Tenant $b): bool
    {
        if ($a === null) {
            return false;
        }

        return $a === $b || ($a->getId() !== null && $a->getId() === $b->getId());
    }
}
