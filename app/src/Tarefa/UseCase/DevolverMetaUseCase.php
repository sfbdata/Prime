<?php

declare(strict_types=1);

namespace App\Tarefa\UseCase;

use App\Entity\Auth\User;
use App\Entity\Notificacao;
use App\Entity\Tarefa\Tarefa;
use App\Entity\Tarefa\TarefaMensagem;
use App\Entity\Tenant\Tenant;
use App\Repository\TarefaMensagemRepository;
use App\Repository\UserTenantRepository;
use App\Service\NotificacaoService;
use App\Tarefa\DTO\DevolverMetaInput;
use App\Tarefa\Enum\AcaoDoDespacho;
use App\Tarefa\Exception\PassagemRecusadaException;
use App\Tarefa\Service\CodigoDoRegistro;
use App\Tarefa\Service\TextoDoDespacho;
use App\Tarefa\Service\TransacaoDaPassagem;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Devolver para quem enviou (desenho 04B L81, L106-108, L165-167; spec `minhas-metas-esteira-2210.md`, D3, L15 e
 * 2.2).
 *
 * Só o responsável devolve, e não quando ele mesmo criou a meta (D3); só a meta pendente (em revisão ela já está
 * com quem criou). Motivo da lista fixa e "o que falta" com pelo menos 5 caracteres, com os erros do desenho. A meta
 * volta para quem enviou: o criador passa a ser o único responsável (o protótipo não trocava, defeito da spec 2.2),
 * o status continua pendente e a etapa da pasta não muda. O histórico grava "Motivo: o que falta" como ato registrado
 * (código D1) com o criador no "para"; o criador é avisado se ainda está no escritório e vê a pasta. Tudo numa
 * transação.
 */
final class DevolverMetaUseCase
{
    public const LIMITE_DO_TEXTO = 2000;

    public function __construct(
        private readonly TransacaoDaPassagem $transacao,
        private readonly TarefaMensagemRepository $mensagens,
        private readonly CodigoDoRegistro $codigo,
        private readonly NotificacaoService $notificacoes,
        private readonly UserTenantRepository $vinculos,
        private readonly TextoDoDespacho $texto,
        private readonly UrlGeneratorInterface $urls,
    ) {
    }

    /** @return string o nome de quem recebeu a meta de volta, para o aviso da tela */
    public function executar(Tarefa $meta, User $quem, Tenant $escritorio, DevolverMetaInput $input, \DateTimeImmutable $agora): string
    {
        $criador = $this->exigirQuePodeDevolver($meta, $quem, $escritorio);
        $oQueFalta = TextoDoDespacho::normalizar($input->oQueFalta);
        if (!in_array($input->motivo, DevolverMetaInput::MOTIVOS, true)) {
            throw PassagemRecusadaException::semMotivo();
        }
        if (mb_strlen($oQueFalta) < DevolverMetaInput::MINIMO_DO_QUE_FALTA) {
            throw PassagemRecusadaException::semOQueFalta();
        }
        if (mb_strlen($oQueFalta) > self::LIMITE_DO_TEXTO) {
            throw PassagemRecusadaException::textoLongo();
        }

        $this->transacao->emTransacao(function () use ($meta, $quem, $escritorio, $criador, $input, $oQueFalta, $agora): void {
            foreach ($meta->getResponsaveis()->toArray() as $responsavel) {
                $meta->removeResponsavel($responsavel);
            }
            $meta->addResponsavel($criador)->setStatus(Tarefa::STATUS_PENDENTE);
            $this->registrar($meta, $quem, $escritorio, $criador, $input->motivo . ': ' . $oQueFalta, $agora);
            $this->avisar($meta, $quem, $escritorio, $criador, $input->motivo, $oQueFalta);
        });

        return self::nome($criador);
    }

    /** @return User quem criou a meta, que a recebe de volta */
    private function exigirQuePodeDevolver(Tarefa $meta, User $quem, Tenant $escritorio): User
    {
        if ($meta->getTenant()?->getId() !== $escritorio->getId()) {
            throw PassagemRecusadaException::foraDoEscritorio();
        }
        if (!self::podeDevolver($meta, $quem)) {
            throw PassagemRecusadaException::semPermissaoParaDevolver();
        }
        if ($meta->getStatus() !== Tarefa::STATUS_PENDENTE) {
            throw PassagemRecusadaException::devolucaoForaDePendente();
        }

        return $meta->getCriadoPor() ?? throw PassagemRecusadaException::semQuemEnviou();
    }

    /** D3: responsável pela meta que não seja quem a criou. A tela usa a mesma regra para mostrar o botão. */
    public static function podeDevolver(Tarefa $meta, User $quem): bool
    {
        if ($meta->getCriadoPor()?->getId() === $quem->getId()) {
            return false;
        }
        foreach ($meta->getResponsaveis() as $responsavel) {
            if ($responsavel->getId() === $quem->getId()) {
                return true;
            }
        }

        return false;
    }

    private function registrar(Tarefa $meta, User $quem, Tenant $escritorio, User $criador, string $texto, \DateTimeImmutable $agora): void
    {
        $ato = (new TarefaMensagem())
            ->setUsuario($quem)
            ->setTenant($escritorio)
            ->setMensagem($this->texto->paraHtml($texto))
            ->setAcao(AcaoDoDespacho::Devolucao)
            ->setDestinatario($criador)
            ->setCriadoEm($agora);
        $ato->registrarAutoria(
            (string) ($quem->getFullName() ?? $quem->getEmail()),
            $this->vinculos->findPorUserETenant($quem, $escritorio)?->getCargo()?->getNome(),
        );
        $meta->addMensagem($ato);
        $this->mensagens->salvar($ato);
        $this->mensagens->confirmar();
        $this->codigo->gravar($ato);
    }

    /** O aviso do desenho (04B L167), no tipo "tarefa pendente" da Central (spec 2.2). */
    private function avisar(Tarefa $meta, User $quem, Tenant $escritorio, User $criador, string $motivo, string $oQueFalta): void
    {
        if (!$this->vinculos->existeVinculoAtivo($criador, $escritorio) || !$this->notificacoes->veAPastaDaMeta($meta, $criador)) {
            return;
        }
        $this->notificacoes->criar(
            $criador,
            $escritorio,
            Notificacao::TIPO_TAREFA_PENDENTE,
            sprintf('Meta devolvida · Pasta %s', $meta->getPasta()->getNup() ?? '#' . $meta->getPasta()->getId()),
            sprintf('%s devolveu "%s": %s. %s', self::nome($quem), $meta->getTitulo(), $motivo, $oQueFalta),
            $meta,
        )->setUrl($this->urls->generate('tarefa_show', ['id' => $meta->getId()]));
    }

    private static function nome(User $pessoa): string
    {
        return mb_convert_case(trim((string) ($pessoa->getFullName() ?? $pessoa->getEmail())), MB_CASE_TITLE);
    }
}
