<?php

declare(strict_types=1);

namespace App\Comercial\Service;

use App\Comercial\Campanha\CalendarioDasDatasComemorativas as Calendario;
use App\Comercial\Campanha\ChaveDasCampanhas;
use App\Comercial\Campanha\ComposicaoDaCampanha;
use App\Comercial\Campanha\ModelosDeCampanha;
use App\Comercial\Exception\CampanhaRecusada;
use App\Comercial\DTO\MensagemDaCampanha;
use App\Comercial\Entity\CampanhaDeEmail;
use App\Comercial\Entity\DataComemorativa;
use App\Comercial\Repository\CampanhaDeEmailRepository;
use App\Comercial\Repository\DataComemorativaRepository;
use Psr\Log\LoggerInterface;

/**
 * O passo `datas` da varredura de 15 min (spec `comercial-e-emails.md` §13, F4): para cada data comemorativa ligada
 * cujo dia de envio (a data menos a antecedência, America/Sao_Paulo) é hoje e que já passou das 9h, cria uma campanha
 * única do sistema, na fila: sem autor (`criada_por` nulo), `modelo_chave` = a chave do modelo escolhido para a data (M5.12; sem escolha, `data-<id>`), o modelo congelado e o público
 * da data. Daí em diante é o expansor: vazão, teto, B1, descadastro (lista `comercial_campanhas`), Relatório e
 * Cancelar.
 *
 *  - Uma vez por ano: `chave_idempotencia = data:<id>:<ano da data>` (UNIQUE, `ON CONFLICT DO NOTHING`); o Ano Novo
 *    "1 dia antes" sai em 31/12 com o ano seguinte. As voltas seguintes do mesmo dia não criam outra.
 *  - Janela: das 9h às 23:59 do dia de envio. Ligar a data nesse dia, depois das 9h, ainda manda; passado o dia, nada
 *    é recuperado.
 *  - Chave do envio de campanhas desligada (o interruptor da Central de envios; sem ele, `COMERCIAL_CAMPANHAS_LIGADAS`):
 *    não cria nada, não represa nada e não registra log. Religada no mesmo dia, a data devida sai na volta seguinte.
 *  - As 17 do calendário, com o estado do banco ou, sem linha, o {@see DataComemorativa::PADRAO}.
 *
 * A trilha é a manual das campanhas (`camp_conf`, `{publico, frequencia, total}`), sem autor. O total nasce 0: quem
 * resolve o público é a execução do expansor, que grava o total real.
 */
final class DisparoDasDatasComemorativas
{
    public function __construct(
        private readonly DataComemorativaRepository $datas,
        private readonly CampanhaDeEmailRepository $campanhas,
        private readonly ModelosEfetivos $modelos,
        private readonly ComposicaoDaCampanha $composicao,
        private readonly ChaveDasCampanhas $chave,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** Devolve quantas campanhas nasceram nesta volta. */
    public function disparar(\DateTimeImmutable $agora): int
    {
        $local = $agora->setTimezone(new \DateTimeZone(Calendario::FUSO));
        if ((int) $local->format('G') < Calendario::HORA_DO_ENVIO) {
            return 0;
        }
        $devidas = $this->devidasHoje($local);
        if ($devidas === []) {
            return 0;
        }
        // Chave geral desligada: nada, e sem log (a volta de 15 min o repetiria o dia inteiro; a tela já avisa).
        if (!$this->chave->ligada()) {
            return 0;
        }
        $criadas = 0;
        foreach ($devidas as $chave => [$data, $publico, $escolhido]) {
            $criadas += $this->criar($chave, $data, $publico, $escolhido, $agora) ? 1 : 0;
        }

        return $criadas;
    }

    /** @return array<string, array{\DateTimeImmutable, string, ?string}> as datas ligadas com envio hoje: a data, o público e o modelo escolhido */
    private function devidasHoje(\DateTimeImmutable $local): array
    {
        $devidas = [];
        $estados = $this->datas->estados();
        foreach (Calendario::chaves() as $chave) {
            $estado = DataComemorativa::estadoOuPadrao($estados, $chave);
            $data = $estado['ligada'] ? Calendario::devidaEm($chave, $estado['antecedencia'], $local) : null;
            if ($data !== null) {
                $devidas[$chave] = [$data, $estado['publico'], $estado['modelo']];
            }
        }

        return $devidas;
    }

    /**
     * O modelo da mensagem: o escolhido para a data (versão editada, se houver) ou o `data-<id>` dela. O escolhido que
     * não existe mais (próprio excluído) ou que não compõe uma campanha válida volta ao padrão da data, com log.
     *
     * @return array{MensagemDaCampanha, string, string} a mensagem, a chave do modelo e o conteúdo congelado
     */
    private function mensagem(string $chave, ?string $escolhido): array
    {
        $padrao = ModelosDeCampanha::PREFIXO_DE_DATA . $chave;
        foreach ($escolhido !== null && $escolhido !== $padrao ? [$escolhido, $padrao] : [$padrao] as $tentativa) {
            $modelo = $this->modelos->porChave($tentativa);
            if ($modelo === null) {
                $this->logger->warning('Comercial: modelo escolhido da data comemorativa não existe mais; vale o padrão da data.', ['data' => $chave, 'modelo' => $tentativa]);
                continue;
            }
            try {
                $mensagem = MensagemDaCampanha::deArray($modelo);
                ComposicaoDaCampanha::exigirAncoraDeVagas($mensagem); // o `vagas` editado sem o botão das vagas não sai (§11)

                return [$mensagem, $modelo['chave'], $this->composicao->congelar($mensagem)];
            } catch (CampanhaRecusada $e) {
                $this->logger->warning('Comercial: modelo escolhido da data comemorativa não compõe uma campanha; vale o padrão da data.', ['data' => $chave, 'modelo' => $tentativa, 'motivo' => $e->getMessage()]);
            }
        }

        throw new \LogicException('Modelo da data ausente: ' . $chave);
    }

    private function criar(string $chave, \DateTimeImmutable $data, string $publico, ?string $escolhido, \DateTimeImmutable $agora): bool
    {
        [$mensagem, $modeloChave, $conteudo] = $this->mensagem($chave, $escolhido);
        $linha = [
            'chave' => 'data:' . $chave . ':' . $data->format('Y'), 'assunto' => $mensagem->assunto, 'modelo_chave' => $modeloChave,
            'modelo_nome' => $mensagem->nome, 'modo' => $mensagem->modo, 'selo' => $mensagem->selo, 'titulo' => $mensagem->titulo,
            'conteudo' => $conteudo, 'publico' => $publico, 'parametros' => null,
            'frequencia' => CampanhaDeEmail::UNICA, 'agendada_para' => null, 'proxima' => $agora, 'estado' => CampanhaDeEmail::NA_FILA,
            'total' => 0, 'criada_por' => null, 'agora' => $agora,
        ];
        $id = $this->campanhas->emTransacao(function () use ($linha, $publico, $agora): ?int {
            $id = $this->campanhas->inserir($linha);
            if ($id !== null) {
                $this->campanhas->auditar($id, CampanhaDeEmailRepository::CONFIRMADA, ['publico' => $publico, 'frequencia' => CampanhaDeEmail::UNICA, 'total' => 0], null, null, $agora);
            }

            return $id;
        });
        if ($id !== null) {
            $this->logger->info('Comercial: campanha da data comemorativa criada.', ['data' => $chave, 'campanhaId' => $id]);
        }

        return $id !== null;
    }
}
