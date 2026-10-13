<?php

declare(strict_types=1);

namespace App\Tarefa\Service;

use App\Entity\Tarefa\Tarefa;
use App\Entity\Tenant\Tenant;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

/**
 * Lembra, na SESSÃO de quem reabriu, a data de conclusão que o "Reabrir meta" apagou (PMC-061).
 *
 * O "Desfazer" do aviso "Meta reaberta" manda o `tarefa_concluir` de novo. Sem esta memória ele
 * gravaria AGORA como data de conclusão, e a meta concluída no dia X passaria a dizer que foi
 * concluída hoje (e "fora do prazo", se o prazo já passou). A data anterior não vem do navegador:
 * fica guardada aqui, no servidor, no momento do reabrir — o cliente só pede "desfaça" e não tem
 * como escolher data nenhuma.
 *
 * Cada lembrança vale para UMA meta do escritório da sessão, por `JANELA_SEGUNDOS`, e é consumida
 * no primeiro uso. Não há migration: nada disto vai para o banco; o que vai é a conclusão
 * restaurada, auditada pelo `AuditLogSubscriber` (Tarefa é Auditavel) como qualquer alteração.
 */
final class ReaberturaDesfazivelDaMeta
{
    /** O aviso com "Desfazer" fica 4,2 s na tela; a folga cobre rede lenta, não uso tardio. */
    public const JANELA_SEGUNDOS = 600;

    private const CHAVE_SESSAO = 'tarefa.reabertura_desfazivel';

    private const FORMATO = 'Y-m-d\TH:i:s.uP';

    public function guardar(SessionInterface $sessao, Tarefa $tarefa, Tenant $tenant, \DateTimeImmutable $dataConclusaoAnterior, \DateTimeImmutable $agora): void
    {
        $lembrancas = $this->vigentes($sessao, $agora);
        $lembrancas[$this->chave($tarefa, $tenant)] = [
            'data' => $dataConclusaoAnterior->format(self::FORMATO),
            'em'   => $agora->getTimestamp(),
        ];

        $sessao->set(self::CHAVE_SESSAO, $lembrancas);
    }

    /**
     * Devolve a data de conclusão lembrada para esta meta (e a esquece), ou null quando não há
     * lembrança vigente — outra meta, outro escritório, outra sessão ou janela vencida.
     */
    public function consumir(SessionInterface $sessao, Tarefa $tarefa, Tenant $tenant, \DateTimeImmutable $agora): ?\DateTimeImmutable
    {
        $lembrancas = $this->vigentes($sessao, $agora);
        $chave      = $this->chave($tarefa, $tenant);
        $lembranca  = $lembrancas[$chave] ?? null;
        unset($lembrancas[$chave]);
        $sessao->set(self::CHAVE_SESSAO, $lembrancas);

        if ($lembranca === null) {
            return null;
        }

        $data = \DateTimeImmutable::createFromFormat(self::FORMATO, $lembranca['data']);

        return $data === false ? null : $data;
    }

    public function esquecer(SessionInterface $sessao, Tarefa $tarefa, Tenant $tenant, \DateTimeImmutable $agora): void
    {
        $lembrancas = $this->vigentes($sessao, $agora);
        unset($lembrancas[$this->chave($tarefa, $tenant)]);
        $sessao->set(self::CHAVE_SESSAO, $lembrancas);
    }

    /** @return array<string, array{data: string, em: int}> */
    private function vigentes(SessionInterface $sessao, \DateTimeImmutable $agora): array
    {
        $guardadas = $sessao->get(self::CHAVE_SESSAO, []);
        if (!is_array($guardadas)) {
            return [];
        }

        $vigentes = [];
        foreach ($guardadas as $chave => $lembranca) {
            if (!is_string($chave) || !is_array($lembranca) || !is_string($lembranca['data'] ?? null) || !is_int($lembranca['em'] ?? null)) {
                continue;
            }
            $idade = $agora->getTimestamp() - $lembranca['em'];
            if ($idade < 0 || $idade > self::JANELA_SEGUNDOS) {
                continue;
            }
            $vigentes[$chave] = ['data' => $lembranca['data'], 'em' => $lembranca['em']];
        }

        return $vigentes;
    }

    private function chave(Tarefa $tarefa, Tenant $tenant): string
    {
        return $tenant->getId() . ':' . $tarefa->getId();
    }
}
