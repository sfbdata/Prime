<?php

declare(strict_types=1);

namespace App\Cobranca\UseCase;

use App\Cobranca\Entity\EventoHistorico;
use App\Cobranca\Enum\QualificacaoContato;
use App\Cobranca\Enum\TipoEventoHistorico;
use App\Cobranca\Exception\CasoEncerradoException;
use App\Cobranca\Exception\CasoNaoEncontradoException;
use App\Cobranca\Repository\CasoCobrancaRepository;
use App\Cobranca\Service\RegistrarEventoHistorico;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use Psr\Clock\ClockInterface;

/**
 * Carimba no histórico o que a equipe descobriu sobre o devedor, em UM clique (spec §3, 2026-07-27).
 *
 * História: a funcionária está ao telefone. O devedor diz que não vai pagar, ou o número dá "não
 * existe", ou ele promete pagar sexta. Ela não vai abrir o formulário de "Registrar contato" no meio da
 * ligação — clica no botão do painel e segue. Por isso não há DTO nem Form aqui: não existe formulário
 * a validar, só um enum vindo de um botão. O valor da funcionalidade ESTÁ em ser instantânea.
 *
 * Como toda escrita daqui, é apenas um MOVIMENTO: não cria obrigação, não altera valor, não mexe em
 * prazo. O único efeito é um `EventoHistorico` do tipo `QualificacaoContato`, e por isso o flush
 * acontece aqui mesmo.
 *
 * A qualificação pertence ao CASO, não à pessoa (decisão do dono em 2026-07-27): a listinha do painel é
 * da cobrança inteira. O `dados = {qualificacao: <value>}` é o que permite a listinha filtrar e
 * reidratar o enum; a `descricao` guarda o rótulo para o Histórico normal renderizar a linha sem
 * precisar conhecer o enum.
 *
 * Duas escritas ao mesmo tempo são permitidas de propósito — nada aqui é único. Qualificar duas vezes
 * seguidas registra as duas: são dois fatos observados, e o histórico é append-only.
 *
 * O caso é resolvido por id + tenant (guarda multi-tenant): inexistente ou de outro escritório
 * interrompe a operação. Caso encerrado não recebe novos lançamentos (SPEC §17), pelo mesmo motivo que
 * não recebe contato nem anotação: o ciclo daquele caso terminou.
 *
 * O `ocorridoEm` é carimbado com o relógio INJETADO, não com o `new \DateTimeImmutable()` que o
 * construtor da entidade usaria por omissão. Não é preciosismo: quem mede a janela de 5 minutos do
 * desfazer é o `ClockInterface`, e se o registro fosse marcado por outro relógio os dois lados da mesma
 * regra andariam separados — a recusa "passou dos 5 minutos" viraria intestável de forma determinística.
 * (`RegistrarAnotacaoUseCase` ainda tem esse desalinhamento; aqui ele não nasce.)
 */
final class RegistrarQualificacaoContatoUseCase
{
    public function __construct(
        private readonly CasoCobrancaRepository $casoRepository,
        private readonly RegistrarEventoHistorico $registrarEvento,
        private readonly ClockInterface $clock,
    ) {
    }

    public function executar(
        int $casoId,
        QualificacaoContato $qualificacao,
        Tenant $tenant,
        User $usuario,
    ): EventoHistorico {
        // Guarda multi-tenant: só um caso do próprio escritório pode ser qualificado.
        $caso = $this->casoRepository->findOneByIdDoTenant($casoId, $tenant);

        if ($caso === null) {
            throw new CasoNaoEncontradoException($casoId);
        }

        if ($caso->estaEncerrado()) {
            throw new CasoEncerradoException((int) $caso->getId());
        }

        return $this->registrarEvento->registrar(
            $caso,
            TipoEventoHistorico::QualificacaoContato,
            $usuario,
            $qualificacao->label(),
            ['qualificacao' => $qualificacao->value],
            true,
            $this->clock->now(),
        );
    }
}
