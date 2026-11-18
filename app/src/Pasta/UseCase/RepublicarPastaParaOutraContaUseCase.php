<?php

declare(strict_types=1);

namespace App\Pasta\UseCase;

use App\Entity\Auth\User;
use App\Entity\Notificacao;
use App\Entity\Tenant\Tenant;
use App\Pasta\DTO\CompartilharPastaInput;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaCompartilhamento;
use App\Pasta\Exception\CompartilhamentoRecusadoException;
use App\Pasta\Repository\PastaCompartilhamentoRepository;
use App\Pasta\Service\AcessoAPasta;
use App\Pasta\Service\CodigoPessoal;
use App\Pasta\Service\EscopoDePastas;
use App\Repository\UserTenantRepository;
use App\Service\NotificacaoService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * "Republicar para outra conta" (spec acesso-restrito-e-compartilhar §4.5, fatia 8; desenho L3 aba "Outra conta
 * (código)", L5 e L6): convida, pelo código pessoal `BJ-XXXX-XXXX-XXXX`, uma pessoa que não é da equipe. O convite nasce
 * pendente e só vale quando ela aceita em "Recebidas"; até a fatia 9 ele não abre nada no escritório da pasta.
 *
 * Quem republica: quem edita a pasta e não tem acesso restrito (o mesmo da equipe). O destino: o dono ativo do código,
 * que não é quem republica e não tem vínculo nenhum com o escritório da pasta (esse vai pela aba Equipe). O "1 clique"
 * do menu manda o `destinoId` em vez do código, aceito só para quem já recebeu desta pessoa por código neste escritório.
 * Um convite vivo (pendente ou aceito, dentro da validade) por pasta + pessoa; o vencido dá lugar ao novo.
 *
 * O aviso vai para o sino do destino no primeiro escritório ativo dele, sem nenhum dado da pasta nem da conta de
 * origem (a `Notificacao` é auditada lá).
 */
final class RepublicarPastaParaOutraContaUseCase
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PastaCompartilhamentoRepository $compartilhamentos,
        private readonly UserTenantRepository $vinculos,
        private readonly AcessoAPasta $acessoAPasta,
        private readonly EscopoDePastas $escopo,
        private readonly CodigoPessoal $codigoPessoal,
        private readonly NotificacaoService $notificacoes,
        private readonly UrlGeneratorInterface $urls,
        private readonly ClockInterface $relogio,
    ) {
    }

    public function executar(int $pastaId, CompartilharPastaInput $input, User $autor, Tenant $tenant): PastaCompartilhamento
    {
        $pasta   = $this->pastaQueOAutorRepublica($pastaId, $autor, $tenant);
        $destino = $this->destino($input, $autor, $tenant);
        $agora   = $this->relogio->now();
        if ($input->validoAte !== null && $input->validoAte->format('Y-m-d') < $agora->format('Y-m-d')) {
            throw new CompartilhamentoRecusadoException(CompartilhamentoRecusadoException::VALIDADE_PASSADA);
        }
        $vencido = $this->vagaLivreOuVencida($pasta, $destino, $agora);

        return $this->em->wrapInTransaction(function () use ($pasta, $destino, $input, $autor, $agora, $vencido): PastaCompartilhamento {
            if ($vencido !== null) {
                $vencido->encerrar($autor, $agora);
                $this->em->flush();
            }
            $convite = PastaCompartilhamento::externo($pasta, $autor, $destino, $input->permissoes(), $input->validoAte, $input->mensagem, $agora);
            $this->compartilhamentos->salvar($convite);
            $this->avisar($destino);
            $this->em->flush();

            return $convite;
        });
    }

    private function pastaQueOAutorRepublica(int $pastaId, User $autor, Tenant $tenant): Pasta
    {
        $pasta = $this->em->getRepository(Pasta::class)->findOneBy(['id' => $pastaId, 'tenant' => $tenant]);
        if ($pasta === null || $this->escopo->ehRestritoNoEscritorio($autor, $tenant) || !$this->acessoAPasta->podeEditar($autor, $pasta)) {
            throw new CompartilhamentoRecusadoException(CompartilhamentoRecusadoException::SEM_PERMISSAO, daPessoa: true);
        }

        return $pasta;
    }

    private function destino(CompartilharPastaInput $input, User $autor, Tenant $tenant): User
    {
        $destino = $input->codigo !== null ? $this->donoDoCodigo($input->codigo, $autor) : $this->destinoDoUmClique($input, $autor, $tenant);
        if ($this->vinculos->findPorUserETenant($destino, $tenant) !== null) {
            throw new CompartilhamentoRecusadoException(CompartilhamentoRecusadoException::CODIGO_DA_EQUIPE);
        }

        return $destino;
    }

    private function donoDoCodigo(string $digitado, User $autor): User
    {
        $codigo = CodigoPessoal::normalizar($digitado) ?? throw new CompartilhamentoRecusadoException(CompartilhamentoRecusadoException::CODIGO_INVALIDO);
        if ($codigo === $autor->getCodigoPessoal()) {
            throw new CompartilhamentoRecusadoException(CompartilhamentoRecusadoException::CODIGO_PROPRIO);
        }

        return $this->codigoPessoal->dono($codigo) ?? throw new CompartilhamentoRecusadoException(CompartilhamentoRecusadoException::CODIGO_NAO_ENCONTRADO);
    }

    /** O "1 clique": só quem já recebeu desta pessoa por código neste escritório. */
    private function destinoDoUmClique(CompartilharPastaInput $input, User $autor, Tenant $tenant): User
    {
        $destino = $input->destinoId !== null ? $this->em->getRepository(User::class)->find($input->destinoId) : null;
        if ($destino === null || !$destino->isActive() || $destino->getId() === $autor->getId() || !$this->compartilhamentos->jaRepublicouPara($autor, $destino, $tenant)) {
            throw new CompartilhamentoRecusadoException(CompartilhamentoRecusadoException::CODIGO_INVALIDO);
        }

        return $destino;
    }

    /**
     * Convite vivo recusa (antes da transação, que fecharia o EntityManager na exceção); o vencido, pendente ou aceito,
     * volta para ser encerrado e gravado antes do novo, para o índice único não barrar.
     */
    private function vagaLivreOuVencida(Pasta $pasta, User $destino, \DateTimeImmutable $agora): ?PastaCompartilhamento
    {
        $ocupando = $this->compartilhamentos->ocupandoAVaga($pasta, $destino);
        if ($ocupando !== null && !$ocupando->venceuEm($agora)) {
            throw new CompartilhamentoRecusadoException(CompartilhamentoRecusadoException::JA_REPUBLICADA);
        }

        return $ocupando;
    }

    /** O aviso no sino (Central) do primeiro escritório ativo do destino; sem escritório, só a caixa "Recebidas". */
    private function avisar(User $destino): void
    {
        $vinculos = $this->vinculos->findActiveByUser($destino);
        usort($vinculos, static fn ($a, $b): int => (int) $a->getTenant()->getId() <=> (int) $b->getTenant()->getId());
        $escritorio = ($vinculos[0] ?? null)?->getTenant();
        if ($escritorio === null) {
            return;
        }
        $this->notificacoes->prepararNotificacao(
            $destino,
            $escritorio,
            Notificacao::TIPO_PASTA_RECEBIDA,
            'Uma pasta foi republicada para você',
            $this->urls->generate('expediente_index', ['recebidas' => 1]),
            'Uma pasta de outra conta foi republicada para você. Aceite ou recuse em Recebidas, na Lista de pastas.',
        );
    }
}
