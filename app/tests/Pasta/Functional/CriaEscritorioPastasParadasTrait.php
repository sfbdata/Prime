<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Djen\Entity\PublicacaoDjen;
use App\Entity\Notificacao;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaAndamento;
use App\Pasta\Entity\PastaDocumento;
use App\Pasta\Entity\PastaPagamento;
use App\Pasta\Repository\EnvioDoRelatorioPastasParadasRepository;
use App\Pasta\Service\AndamentoDaPasta;
use App\Pasta\Service\MontagemDoRelatorioPastasParadas;
use App\Pasta\Service\PastasVisiveisNoRelatorio;
use App\Pasta\UseCase\EnviarRelatorioPastasParadasUseCase;
use App\Processo\Entity\MovimentacaoProcesso;
use App\Processo\Entity\Processo;
use App\Repository\UserTenantRepository;
use App\Service\NotificacaoService;
use App\Service\PermissionChecker;
use App\Tenant\Service\RegraDosPrimarios;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Escritório para o relatório de pastas paradas, sobre o do relatório de pastas sem processo
 * (perfis, pessoas, pastas, registros, acesso item a item): a data de abertura, as fontes de
 * movimentação (registro, movimentação do processo, publicação, andamento), documentos,
 * pagamentos e a leitura dos avisos gravados.
 *
 * As datas são absolutas: os testes rodam com o relógio parado na primeira segunda-feira de
 * outubro de 2026 (`PRIMEIRA_SEGUNDA`).
 */
trait CriaEscritorioPastasParadasTrait
{
    use CriaEscritorioSemProcessoTrait;

    private function pastaAbertaEm(Tenant $tenant, string $nup, string $abertura, ?\App\Entity\Auth\User $responsavel = null): Pasta
    {
        $pasta = $this->novaPasta($tenant, $nup, $responsavel);
        $pasta->setDataAbertura(new \DateTimeImmutable($abertura));
        $this->em()->flush();

        return $pasta;
    }

    /** Registro de pessoa com a data pedida (o `criadaEm` da entidade é a hora real). */
    private function registrarEm(Pasta $pasta, string $quando): void
    {
        $this->registrar($pasta, $pasta->getResponsavel() ?? $this->primeiroUsuario($pasta->getTenant()), 'Contato com o cliente');
        $this->em()->getConnection()->executeStatement(
            'UPDATE pasta_mensagem SET criada_em = ? WHERE id = (SELECT MAX(id) FROM pasta_mensagem WHERE pasta_id = ?)',
            [$quando, $pasta->getId()],
        );
    }

    private function primeiroUsuario(Tenant $tenant): \App\Entity\Auth\User
    {
        $vinculo = $this->em()->getRepository(\App\Entity\Auth\UserTenant::class)->findOneBy(['tenant' => $tenant]);
        self::assertNotNull($vinculo);

        return $vinculo->getUser();
    }

    private function processoDaPasta(Pasta $pasta, string $numero, string $situacao = ''): Processo
    {
        $this->vincularProcessoNovo($pasta, $numero);
        $processo = $pasta->getProcessoPrincipal();
        self::assertNotNull($processo);
        $processo->setSituacaoProcesso($situacao);
        $processo->setSiglaTribunal('TJDFT');
        $this->em()->flush();

        return $processo;
    }

    private function movimentar(Processo $processo, string $data): void
    {
        $mov = new MovimentacaoProcesso();
        $mov->setProcesso($processo);
        $mov->setTenant($processo->getTenant());
        $mov->setDescricao('Conclusos para despacho');
        $mov->setDataMovimentacao(new \DateTime($data));
        $this->em()->persist($mov);
        $this->em()->flush();
    }

    private function publicar(Tenant $tenant, string $numero, string $data): void
    {
        $pub = new PublicacaoDjen();
        $pub->setTenant($tenant);
        $pub->setDjenId((string) random_int(1, \PHP_INT_MAX));
        $pub->setNumeroProcesso((string) preg_replace('/\D/', '', $numero));
        $pub->setSiglaTribunal('TJDFT');
        $pub->setDataDisponibilizacao(new \DateTimeImmutable($data));
        $this->em()->persist($pub);
        $this->em()->flush();
    }

    /** Andamento gravado na fase `$atual` da via judicial, alcançada em `$desde`. */
    private function andamento(Pasta $pasta, int $atual, string $desde): void
    {
        $andamento = new PastaAndamento($pasta->getTenant(), $pasta, AndamentoDaPasta::via($pasta));
        $andamento->gravar($atual, ['em' => [$atual => (new \DateTimeImmutable($desde))->format(\DATE_ATOM)], 'pul' => [], 'extra' => []]);
        $this->em()->persist($andamento);
        $this->em()->flush();
    }

    private function documento(Pasta $pasta, string $titulo, string $categoria = PastaDocumento::CATEGORIA_DEMAIS): void
    {
        $d = new PastaDocumento();
        $d->setTenant($pasta->getTenant());
        $d->setPasta($pasta);
        $d->setTitulo($titulo);
        $d->setCategoria($categoria);
        $d->setCaminhoArquivo('x/' . uniqid() . '.pdf');
        $d->setNomeOriginal(uniqid() . '.pdf');
        $d->setMimeType('application/pdf');
        $d->setTamanhoBytes(10);
        $this->em()->persist($d);
        $this->em()->flush();
    }

    private function pagamento(Pasta $pasta, string $vencimento, bool $pago = false): void
    {
        $p = new PastaPagamento();
        $p->setTenant($pasta->getTenant());
        $p->setPasta($pasta);
        $p->setDescricao('Honorários');
        $p->setValor('100.00');
        $p->setVencimento(new \DateTimeImmutable($vencimento));
        if ($pago) {
            $p->alternarQuitacao(new \DateTimeImmutable($vencimento));
        }
        $this->em()->persist($p);
        $this->em()->flush();
    }

    /**
     * Os avisos do relatório de pastas paradas gravados no escritório, por destinatário.
     *
     * @return array<int, list<Notificacao>>
     */
    private function avisosDasParadas(Tenant $tenant): array
    {
        $this->em()->clear();
        $avisos = $this->em()->getRepository(Notificacao::class)->findBy(
            ['tenant' => $tenant->getId(), 'tipo' => EnviarRelatorioPastasParadasUseCase::TIPO_NOTIFICACAO],
            ['id' => 'ASC'],
        );

        $porPessoa = [];
        foreach ($avisos as $aviso) {
            $porPessoa[(int) $aviso->getUsuario()?->getId()][] = $aviso;
        }

        return $porPessoa;
    }

    private function enviosDasParadas(Tenant $tenant): int
    {
        return (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM relatorio_pastas_paradas_envio WHERE tenant_id = ?', [$tenant->getId()]);
    }

    /** O UseCase de envio com o relógio parado em `$agora`. */
    private function enviarParadasComRelogio(string $agora): EnviarRelatorioPastasParadasUseCase
    {
        $c = static::getContainer();

        return new EnviarRelatorioPastasParadasUseCase(
            $c->get(MontagemDoRelatorioPastasParadas::class),
            $c->get(UserTenantRepository::class),
            $c->get(RegraDosPrimarios::class),
            $c->get(EnvioDoRelatorioPastasParadasRepository::class),
            $c->get(NotificacaoService::class),
            $this->em(),
            $c->get(UrlGeneratorInterface::class),
            new MockClock(new \DateTimeImmutable($agora)),
            $c->get(PermissionChecker::class),
            $c->get(PastasVisiveisNoRelatorio::class),
        );
    }
}
