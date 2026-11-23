<?php

declare(strict_types=1);

namespace App\Comercial\UseCase;

use App\Comercial\Campanha\ComposicaoDaCampanha;
use App\Comercial\Campanha\ImagensDaCampanha;
use App\Comercial\DTO\MensagemDaCampanha;
use App\Comercial\DTO\PedidoDeEdicao;
use App\Comercial\Entity\CampanhaDeEmail;
use App\Comercial\Exception\CampanhaRecusada;
use App\Comercial\Repository\CampanhaDeEmailRepository;
use App\Entity\Auth\User;
use Psr\Clock\ClockInterface;

/**
 * "Editar campanha" (pedido do Farlei M5.3, 23/11/2026; decisões do Coordenador): muda o conteúdo (a cópia congelada) e a
 * agenda (data e frequência) de uma campanha que ainda não começou a sair. Numa transação:
 *
 *   1. trava a campanha (`FOR UPDATE`; o expansor também a trava para materializar) e confere
 *      {@see CampanhaDeEmailRepository::condicaoEditavel()}: `agendada` e `na_fila` que o expansor ainda não pegou, ou
 *      `recorrente` entre duas execuções. Depois disso, {@see self::JA_COMECOU};
 *   2. valida o conteúdo como a confirmação valida (mensagem, âncora de vagas, imagem vigente) e a data (só futura);
 *   3. regrava a cópia e a agenda, com o estado coerente (única com data = `agendada`; única sem data = `na_fila`, agora;
 *      outra frequência = `recorrente`), e a trilha manual `camp_edit` com só `{frequencia, agendada}`.
 *
 * O público e a chave do modelo não mudam (a chave do pedido é ignorada: vale a da campanha). Null se a campanha não existe.
 */
final class EditarCampanhaUseCase
{
    public const JA_COMECOU = 'Só dá para editar antes de começar a sair: esta campanha já começou.';
    public const ATUALIZADA = 'Campanha atualizada.';
    private const ENDERECO_DE_CONFERENCIA = 'conferencia@bluejus.com.br';

    public function __construct(
        private readonly CampanhaDeEmailRepository $campanhas,
        private readonly ComposicaoDaCampanha $composicao,
        private readonly ImagensDaCampanha $imagens,
        private readonly ClockInterface $relogio,
    ) {
    }

    public function executar(int $id, PedidoDeEdicao $pedido, User $master): ?string
    {
        $agora = $this->relogio->now();

        return $this->campanhas->emTransacao(function () use ($id, $pedido, $master, $agora): ?string {
            $campanha = $this->campanhas->travarParaEdicao($id);
            if ($campanha === null) {
                return null;
            }
            if (!$this->campanhas->editavel($id)) {
                throw new CampanhaRecusada(self::JA_COMECOU);
            }
            // A chave do pedido não vale: o modelo da campanha não muda (nem a regra do público que ele decide).
            $mensagem = MensagemDaCampanha::deArray(array_merge($pedido->modelo, ['chave' => $campanha['modelo_chave']]));
            $this->exigirValido($mensagem, $pedido, $agora);
            $this->campanhas->regravar($id, [
                'assunto' => $mensagem->assunto, 'modelo_nome' => $mensagem->nome !== '' ? $mensagem->nome : $campanha['modelo_nome'],
                'modo' => $mensagem->modo, 'selo' => $mensagem->selo, 'titulo' => $mensagem->titulo,
                'conteudo' => $this->composicao->congelar($mensagem), 'frequencia' => $pedido->frequencia,
            ] + self::agendaDaEdicao($campanha, $pedido, $agora));
            $this->imagens->renovarPeloEnvio($mensagem, $agora);
            $this->campanhas->auditar(
                $id,
                CampanhaDeEmailRepository::EDITADA,
                ['frequencia' => $pedido->frequencia, 'agendada' => $pedido->agendarPara?->format('Y-m-d H:i')],
                $master->getId(),
                $master->getEmail(),
                $agora,
            );

            return self::ATUALIZADA;
        });
    }

    /**
     * A agenda regravada, com o estado coerente: única com data = `agendada`; única sem data = `na_fila`, agora; outra
     * frequência = `recorrente`.
     *
     * @param array<string, mixed> $campanha
     *
     * @return array{agendada_para: ?\DateTimeImmutable, proxima: \DateTimeImmutable, estado: string}
     */
    private static function agendaDaEdicao(array $campanha, PedidoDeEdicao $pedido, \DateTimeImmutable $agora): array
    {
        $recorrente = $pedido->frequencia !== CampanhaDeEmail::UNICA;
        $agendada = $pedido->agendarPara ?? ($recorrente ? $agora : null);
        $proxima = $pedido->agendarPara ?? $agora;
        if ($recorrente && $campanha['estado'] === CampanhaDeEmail::RECORRENTE && self::mesmaData($pedido->agendarPara, $campanha['proxima_execucao'])) {
            // Recorrente que continua recorrente sem mexer na data: a âncora (o dia 31 da mensal, por exemplo) e a
            // próxima execução ficam como estavam; a tela só devolve a próxima, e trocar a âncora por ela a mudaria.
            $agendada = $campanha['agendada_para'] !== null ? new \DateTimeImmutable($campanha['agendada_para'], $agora->getTimezone()) : $agendada;
            $proxima = $campanha['proxima_execucao'] !== null ? new \DateTimeImmutable($campanha['proxima_execucao'], $agora->getTimezone()) : $proxima;
        }

        return [
            'agendada_para' => $agendada, 'proxima' => $proxima,
            'estado' => $recorrente ? CampanhaDeEmail::RECORRENTE : ($pedido->agendarPara !== null ? CampanhaDeEmail::AGENDADA : CampanhaDeEmail::NA_FILA),
        ];
    }

    /**
     * A data do pedido é a próxima execução que a tela mostrou (no minuto) ou vem vazia (a próxima já passou: o expansor
     * atrasado cuida dela). Nos dois casos o Master não mexeu na data.
     */
    private static function mesmaData(?\DateTimeImmutable $pedida, ?string $proxima): bool
    {
        if ($pedida === null) {
            return true;
        }

        return $proxima !== null && $pedida->format('Y-m-d H:i') === (new \DateTimeImmutable($proxima, $pedida->getTimezone()))->format('Y-m-d H:i');
    }

    private function exigirValido(MensagemDaCampanha $mensagem, PedidoDeEdicao $pedido, \DateTimeImmutable $agora): void
    {
        // Monta uma vez para um endereço de conferência: assunto ou conteúdo vazio é recusado com a mensagem da 2a.
        $this->composicao->mensagemPara($mensagem, self::ENDERECO_DE_CONFERENCIA, null, 'campanha:0');
        ComposicaoDaCampanha::exigirAncoraDeVagas($mensagem);
        $this->imagens->exigirVigente($mensagem, $agora);
        if ($pedido->agendarPara !== null && $pedido->agendarPara <= $agora) {
            throw new CampanhaRecusada(ConfirmarCampanhaUseCase::DATA_PASSADA);
        }
    }
}
