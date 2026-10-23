<?php

declare(strict_types=1);

namespace App\Cobranca\UseCase;

use App\Cobranca\DTO\EditarAnotacaoInput;
use App\Cobranca\Entity\EventoHistorico;
use App\Cobranca\Exception\AnotacaoNaoEditavelException;
use App\Cobranca\Exception\CasoEncerradoException;
use App\Cobranca\Exception\EventoNaoEncontradoException;
use App\Cobranca\Repository\EventoHistoricoRepository;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Shared\Service\SanitizadorTextoRico;
use App\Tenant\Service\AuditoriaDaEdicaoSemPrazo;
use App\Tenant\Service\PoderDeEdicaoSemPrazo;
use Psr\Clock\ClockInterface;

/**
 * Corrige o texto de uma ANOTAÇÃO da linha do tempo (ajuste 2026-07-22, decisão do dono).
 *
 * História: a funcionária escreveu a anotação com pressa, errou o nome ou o combinado, e percebe logo
 * depois. Ela reescreve o texto — dentro de 48h e só na própria anotação. A linha do tempo passa a
 * exibir "(editado)", para quem lê saber que o texto de hoje não é o que foi escrito na hora.
 *
 * As três guardas moram na entidade (`EventoHistorico::podeSerEditadaPor`): tem de ser ANOTAÇÃO (evento
 * automático não é texto de alguém — reescrever contato ou pagamento falsificaria o histórico), tem de
 * ser do PRÓPRIO AUTOR, e dentro da JANELA. A janela conta de `ocorridoEm` e a edição NÃO a renova,
 * senão editar de hora em hora deixaria a anotação editável para sempre.
 *
 * O relógio é injetado (`ClockInterface`) — nunca `new \DateTimeImmutable()` aqui, para o teste poder
 * posicionar o "agora" nas bordas da janela.
 */
final class EditarAnotacaoUseCase
{
    public function __construct(
        private readonly EventoHistoricoRepository $eventoRepository,
        private readonly ClockInterface $clock,
        private readonly PoderDeEdicaoSemPrazo $poder,
        private readonly AuditoriaDaEdicaoSemPrazo $auditoria,
        private readonly SanitizadorTextoRico $sanitizador,
    ) {
    }

    public function executar(EditarAnotacaoInput $input, Tenant $tenant, User $usuario): EventoHistorico
    {
        // Guarda multi-tenant: evento de outro escritório não existe para quem pede.
        $evento = $this->eventoRepository->findOneByIdDoTenant((int) $input->eventoId, $tenant);

        if ($evento === null) {
            throw new EventoNaoEncontradoException((int) $input->eventoId);
        }

        $caso = $evento->getCaso();
        if ($caso !== null && $caso->estaEncerrado()) {
            throw new CasoEncerradoException((int) $caso->getId());
        }

        $pelaRegra = $this->exigirPermissao($evento, $usuario, $tenant);

        // Mesma limpeza do registro: a edição é outra porta para o mesmo texto.
        $texto = $this->sanitizador->limpar(trim((string) $input->texto)) ?? '';

        if ($this->sanitizador->estaVazio($texto)) {
            throw new \InvalidArgumentException('A anotação não pode ser vazia.');
        }

        // A trilha da edição sem prazo (spec §6) só depois de todas as validações.
        $this->auditoria->registrar(AuditoriaDaEdicaoSemPrazo::EDITAR, $usuario, $tenant, $pelaRegra, EventoHistorico::class, $evento->getId(), $evento->getUsuario()?->getId());
        $evento->reescrever($texto, $this->clock->now());
        $this->eventoRepository->salvar($evento, true);

        return $evento;
    }

    /**
     * Autor dentro das 48h, OU o poder sem prazo (spec edicao-sem-prazo, item 6: Master; primário com a opção). O uso do
     * poder vai para a trilha, gravada por `executar` só depois das validações; aqui só se devolve se valeu a regra de sempre.
     */
    private function exigirPermissao(EventoHistorico $evento, User $usuario, Tenant $tenant): bool
    {
        $agora = $this->clock->now();
        $pelaRegra = $evento->podeSerEditadaPor($usuario, $agora);
        if (!$pelaRegra && !$evento->podeSerEditadaPor($usuario, $agora, $this->poder->de($usuario, $tenant) !== null)) {
            throw new AnotacaoNaoEditavelException();
        }

        return $pelaRegra;
    }
}
