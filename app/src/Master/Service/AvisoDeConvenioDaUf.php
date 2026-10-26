<?php

declare(strict_types=1);

namespace App\Master\Service;

use App\Comercial\Envio\CarteiroInterface;
use App\Comercial\Envio\MensagemDeEmail;
use App\Comercial\Envio\TipoDeEnvio;
use App\Master\Entity\ConvenioDaUf;
use App\Master\Repository\PrimariosDosEscritoriosRepository;
use App\Master\Repository\PainelMasterRepository;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Avisa por e-mail os usuários primários ativos dos escritórios de um estado quando o Master muda o convênio OAB dele
 * (desenho 05 Perfis 1.2 VS 25.10.26, P4c). Pela porta única de envio: um e-mail por pessoa, na fila `comercial`.
 *
 * Tipo `transacional` (aviso do contrato da própria conta, como a resposta da Central) e origem `convenio_oab`. A origem
 * NÃO é isenta da {@see \App\Comercial\Envio\CotaDeEnvio}: todos entram na fila e o handler só envia com vaga no
 * teto de 24 h, adiando o que passar. Em estado grande a entrega pode se espalhar por mais de um dia.
 * Sem teto de destinatários: uma UF grande nunca fica sem poder mudar o convênio.
 */
final class AvisoDeConvenioDaUf
{
    public const ORIGEM = 'convenio_oab';

    public function __construct(
        private readonly PainelMasterRepository $painel,
        private readonly PrimariosDosEscritoriosRepository $primarios,
        private readonly CarteiroInterface $carteiro,
        private readonly TranslatorInterface $tradutor,
    ) {
    }

    /**
     * Quem recebe o aviso: primários ativos dos escritórios ativos da UF.
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

        return $this->primarios->dos($escritorios);
    }

    /**
     * Enfileira um aviso por destinatário, dentro da transação do chamador. `$convenio` nulo = voltou a ser gratuito.
     * Qualquer falha propaga: o UseCase desfaz a gravação, para nunca ficar mudança sem aviso.
     *
     * @param list<array{email: string, nome: string}> $destinatarios
     *
     * @return int quantos avisos entraram na fila
     */
    public function avisar(string $uf, ?ConvenioDaUf $convenio, array $destinatarios): int
    {
        foreach ($destinatarios as $d) {
            $this->carteiro->enviar($this->mensagem($uf, $convenio, $d['email'], $d['nome']));
        }

        return count($destinatarios);
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
