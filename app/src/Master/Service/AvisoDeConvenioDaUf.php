<?php

declare(strict_types=1);

namespace App\Master\Service;

use App\Comercial\Envio\CarteiroInterface;
use App\Comercial\Envio\CotaDeEnvio;
use App\Comercial\Envio\MensagemDeEmail;
use App\Comercial\Envio\TipoDeEnvio;
use App\Master\Entity\ConvenioDaUf;
use App\Master\Exception\ConvenioRecusado;
use App\Master\Repository\PrimariosDosEscritoriosRepository;
use App\Master\Repository\PainelMasterRepository;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Avisa por e-mail os usuários primários ativos dos escritórios de um estado quando o Master muda o convênio OAB dele
 * (desenho 05 Perfis 1.2 VS 25.10.26, P4c). Pela porta única de envio: um e-mail por pessoa, na fila `comercial`.
 *
 * Tipo `transacional` (aviso do contrato da própria conta, como a resposta da Central) e origem `convenio_oab`. A origem
 * NÃO é isenta da {@see CotaDeEnvio}: o handler só envia com vaga no teto de 24 h, dentro da reserva dos que não são
 * campanha. Para uma alteração não consumir a reserva inteira, o teto de destinatários é metade dela (a alteração acima
 * disso é recusada antes de gravar).
 */
final class AvisoDeConvenioDaUf
{
    public const ORIGEM = 'convenio_oab';

    public function __construct(
        private readonly PainelMasterRepository $painel,
        private readonly PrimariosDosEscritoriosRepository $primarios,
        private readonly CarteiroInterface $carteiro,
        private readonly CotaDeEnvio $cota,
        private readonly TranslatorInterface $tradutor,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Quem receberia o aviso. Recusa quando passa do teto, para o UseCase falhar antes de gravar o convênio.
     *
     * @return list<array{email: string, nome: string}>
     */
    public function destinatarios(string $uf): array
    {
        $escritorios = [];
        foreach ($this->painel->escritorios() as $e) {
            if ($e['ativo'] && LocalizacaoDaPessoa::uf($e['estado']) === $uf) {
                $escritorios[] = $e['id'];
            }
        }

        $destinatarios = $this->primarios->dos($escritorios);
        $teto = $this->tetoDeDestinatarios();
        if (count($destinatarios) > $teto) {
            throw new ConvenioRecusado($this->tradutor->trans('master.convenio_email.recusa_teto', ['uf' => $uf, 'n' => count($destinatarios), 'teto' => $teto]));
        }

        return $destinatarios;
    }

    public function tetoDeDestinatarios(): int
    {
        return max(1, intdiv($this->cota->reserva(), 2));
    }

    /**
     * Enfileira um aviso por destinatário. `$convenio` nulo = voltou a ser gratuito. Falha de um envio vai ao log (só
     * com a UF) e não impede os outros nem desfaz a mudança, que já foi gravada.
     *
     * @param list<array{email: string, nome: string}> $destinatarios
     *
     * @return int quantos avisos entraram na fila
     */
    public function avisar(string $uf, ?ConvenioDaUf $convenio, array $destinatarios): int
    {
        $enfileirados = 0;
        foreach ($destinatarios as $d) {
            try {
                $this->carteiro->enviar($this->mensagem($uf, $convenio, $d['email'], $d['nome']));
                ++$enfileirados;
            } catch (\Throwable $erro) {
                $this->logger->error('Aviso de convênio OAB: um e-mail não entrou na fila.', ['uf' => $uf, 'erro' => $erro::class]);
            }
        }

        return $enfileirados;
    }

    public function mensagem(string $uf, ?ConvenioDaUf $convenio, string $email, string $nome): MensagemDeEmail
    {
        $modo = $convenio?->getModo() ?? 'livre';
        $params = [
            'uf' => $uf,
            'valor' => number_format($convenio?->getValor() ?? 0.0, 2, ',', '.'),
            'data' => $convenio?->getDesde()->format('d/m/Y') ?? '',
        ];
        $paragrafos = [
            $this->tradutor->trans('master.convenio_email.ola', ['nome' => self::primeiroNome($nome)]),
            $this->tradutor->trans('master.convenio_email.corpo_' . $modo, $params),
        ];
        if ($convenio?->getMotivo() !== null) {
            $paragrafos[] = $this->tradutor->trans('master.convenio_email.motivo', ['motivo' => $convenio->getMotivo()]);
        }
        $paragrafos[] = $this->tradutor->trans('master.convenio_email.fecho');

        return new MensagemDeEmail(
            para: $email,
            assunto: $this->tradutor->trans('master.convenio_email.assunto_' . $modo, $params),
            corpoHtml: implode('', array_map(static fn (string $p): string => '<p>' . htmlspecialchars($p, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8') . '</p>', $paragrafos)),
            tipo: TipoDeEnvio::Transacional,
            origem: self::ORIGEM,
            nome: $nome,
            titulo: $this->tradutor->trans('master.convenio_email.titulo', ['uf' => $uf]),
            nomeDoRemetente: 'BlueJus',
            motivoDoEnvio: $this->tradutor->trans('master.convenio_email.motivo_do_envio', ['uf' => $uf]),
        );
    }

    private static function primeiroNome(string $nome): string
    {
        $partes = preg_split('/\s+/', trim($nome)) ?: [];

        return (string) ($partes[0] ?? '');
    }
}
