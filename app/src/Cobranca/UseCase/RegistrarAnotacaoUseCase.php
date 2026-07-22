<?php

declare(strict_types=1);

namespace App\Cobranca\UseCase;

use App\Cobranca\DTO\RegistrarAnotacaoInput;
use App\Cobranca\Entity\EventoHistorico;
use App\Cobranca\Enum\TipoEventoHistorico;
use App\Cobranca\Exception\CasoEncerradoException;
use App\Cobranca\Exception\CasoNaoEncontradoException;
use App\Cobranca\Repository\CasoCobrancaRepository;
use App\Cobranca\Service\RegistrarEventoHistorico;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;

/**
 * Registra uma ANOTAÇÃO livre na linha do tempo do Caso de Cobrança (ajuste 2026-07).
 *
 * História: a funcionária acabou de falar com o síndico, ou soube que o devedor mudou de cidade, ou
 * combinou algo que não cabe em nenhum formulário estruturado. Ela escreve na caixa do topo da aba
 * Histórico e aquilo passa a fazer parte da linha do tempo, ao lado dos eventos automáticos
 * (obrigação criada, acordo, pagamento). Espelha o campo de mensagem da timeline da Pasta.
 *
 * Como todo lançamento daqui, é apenas um MOVIMENTO: não cria obrigação, não altera valor, não mexe
 * em prazo (invariável 20). O único efeito é um `EventoHistorico` do tipo `Anotacao`, por isso o
 * flush acontece aqui.
 *
 * APPEND-ONLY, por decisão de projeto: a anotação não é editável nem apagável. O histórico da Pasta é
 * conversa interna e permite editar em 24h; o histórico de cobrança é registro operacional que pode
 * ser levado a juízo e alimenta o relatório do gestor — um registro reescrito depois vale menos como
 * prova e sumiria do relatório sem deixar rastro. Errou? Escreva outra anotação corrigindo, como se
 * faz em livro de ocorrências.
 *
 * O caso é resolvido por id + tenant (guarda multi-tenant): inexistente ou de outro escritório
 * interrompe a operação. Caso encerrado não recebe novos lançamentos (SPEC §17), pelo mesmo motivo
 * que não recebe contato: o ciclo daquele caso terminou.
 */
final class RegistrarAnotacaoUseCase
{
    public function __construct(
        private readonly CasoCobrancaRepository $casoRepository,
        private readonly RegistrarEventoHistorico $registrarEvento,
    ) {
    }

    public function executar(RegistrarAnotacaoInput $input, Tenant $tenant, User $usuario): EventoHistorico
    {
        // Guarda multi-tenant: só um caso do próprio escritório pode receber a anotação.
        $caso = $this->casoRepository->findOneByIdDoTenant((int) $input->casoId, $tenant);

        if ($caso === null) {
            throw new CasoNaoEncontradoException((int) $input->casoId);
        }

        if ($caso->estaEncerrado()) {
            throw new CasoEncerradoException((int) $caso->getId());
        }

        // A timeline renderiza a `descricao` — o texto entra nela como o usuário escreveu, sem prefixo
        // nem moldura. O `trim` evita gravar anotação que só tem espaço/quebra de linha (o NotBlank do
        // DTO já barra a vazia, mas ele não normaliza o que passa).
        $texto = trim((string) $input->texto);

        return $this->registrarEvento->registrar(
            $caso,
            TipoEventoHistorico::Anotacao,
            $usuario,
            $texto,
            null,
            true,
        );
    }
}
