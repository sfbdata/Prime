<?php

declare(strict_types=1);

namespace App\Agenda\UseCase;

use App\Agenda\Contract\EnviadorDePush;
use App\Agenda\DTO\LembreteOutput;
use App\Agenda\Entity\InscricaoDePush;
use App\Agenda\Repository\InscricaoDePushRepository;
use App\Agenda\Repository\PushEnviadoRepository;
use App\Agenda\Service\PermissoesDaAgenda;
use App\Service\PermissionChecker;

/**
 * História: com o navegador fechado, a pessoa recebe o lembrete da Agenda no horário, por push (entrega
 * 5b, prometida no bj-lembretes.js do desenho). A cada minuto o worker varre quem tem navegador inscrito,
 * calcula os lembretes dela como a tela calcula (ListarLembretesUseCase), na hora de parede do fuso do
 * navegador, e manda os que já venceram e começaram há menos de 2 h. Cada lembrete é reservado antes do
 * envio pela chave (pessoa + lembrete): só quem reserva envia, então nada se repete, nem com duas
 * varreduras ao mesmo tempo. Entrega no máximo uma vez: se o serviço de push recusar, o aviso não volta a
 * sair (o cartão da página continua valendo). Inscrições que o navegador cancelou são apagadas.
 */
final class VarrerLembretesDePushUseCase
{
    private const ATRASO_MAXIMO = '-2 hours';

    public function __construct(
        private readonly InscricaoDePushRepository $inscricoes,
        private readonly PushEnviadoRepository $enviados,
        private readonly ListarLembretesUseCase $listarLembretes,
        private readonly PermissionChecker $permissoes,
        private readonly EnviadorDePush $enviador,
        private readonly PermissoesDaAgenda $daAgenda,
    ) {
    }

    /** @return int quantos avisos foram enviados */
    public function executar(\DateTimeImmutable $agora): int
    {
        if (!$this->enviador->ligado()) {
            return 0;
        }
        $envios = [];
        foreach ($this->porPessoa($this->inscricoes->todas()) as $daPessoa) {
            array_push($envios, ...$this->enviosDaPessoa($daPessoa, $agora));
        }
        $vencidas = $this->enviador->enviar($envios);
        if ([] !== $vencidas) {
            $this->inscricoes->remover($vencidas);
        }
        $this->enviados->limparAntigos($agora);

        return count($envios);
    }

    /**
     * @param list<InscricaoDePush> $todas
     *
     * @return list<list<InscricaoDePush>> agrupadas por pessoa e escritório
     */
    private function porPessoa(array $todas): array
    {
        $grupos = [];
        foreach ($todas as $i) {
            $grupos[$i->getUsuario()->getId() . ':' . $i->getTenant()->getId()][] = $i;
        }

        return array_values($grupos);
    }

    /**
     * @param list<InscricaoDePush> $daPessoa
     *
     * @return list<array{inscricao: InscricaoDePush, conteudo: array<string, string>}>
     */
    private function enviosDaPessoa(array $daPessoa, \DateTimeImmutable $agora): array
    {
        $uma = $daPessoa[0];
        $usuario = $uma->getUsuario();
        $tenant = $uma->getTenant();
        if (!$this->permissoes->canAccessModule($usuario, $tenant, 'agenda')) {
            return [];
        }
        $parede = $this->horaDeParede($agora, $uma->getFuso());
        $lembretes = $this->listarLembretes->executar($tenant, $usuario, $parede, $this->daAgenda->verPastas($usuario, $tenant), $this->daAgenda->verPrazos($usuario, $tenant));
        $envios = [];
        foreach ($lembretes as $l) {
            if ($this->venceu($l, $parede) && $this->enviados->reservar($tenant, $usuario, $l->k, $agora)) {
                foreach ($daPessoa as $inscricao) {
                    $envios[] = ['inscricao' => $inscricao, 'conteudo' => $this->conteudo($l)];
                }
            }
        }

        return $envios;
    }

    /** A hora que o relógio do navegador mostra agora, no fuso padrão do servidor (as horas da Agenda são de parede). */
    private function horaDeParede(\DateTimeImmutable $agora, string $fuso): \DateTimeImmutable
    {
        try {
            $local = $agora->setTimezone(new \DateTimeZone($fuso));
        } catch (\Exception) {
            $local = $agora;
        }

        return new \DateTimeImmutable($local->format('Y-m-d H:i:s'));
    }

    private function venceu(LembreteOutput $l, \DateTimeImmutable $parede): bool
    {
        return new \DateTimeImmutable($l->aviso) <= $parede && new \DateTimeImmutable($l->quando) >= $parede->modify(self::ATRASO_MAXIMO);
    }

    /** @return array<string, string> título, corpo, tag (chave do lembrete) e endereço ao clicar */
    private function conteudo(LembreteOutput $l): array
    {
        $hora = (new \DateTimeImmutable($l->quando))->format('H:i');
        $link = null !== $l->pastaId && 'meus' !== $l->tipo
            ? '/pasta/' . $l->pastaId
            : '/agenda' . (null !== $l->eventoId ? '#ev=ev-' . $l->eventoId . '-' . $l->dia : '');

        return [
            'titulo' => 'BlueJus · ' . mb_substr($l->titulo, 0, 120),
            'corpo'  => 'Às ' . $hora . (null !== $l->observacao ? ' · ' . mb_substr($l->observacao, 0, 120) : ''),
            'tag'    => $l->k,
            'url'    => $link,
        ];
    }
}
