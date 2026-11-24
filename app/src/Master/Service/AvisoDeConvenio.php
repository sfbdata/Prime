<?php

declare(strict_types=1);

namespace App\Master\Service;

use App\Comercial\Envio\CarteiroInterface;
use App\Comercial\Envio\MensagemDeEmail;
use App\Comercial\Envio\TipoDeEnvio;
use App\Master\Entity\Convenio;
use App\Master\Enum\BeneficioDoConvenio;
use App\Master\Repository\PrimariosDosEscritoriosRepository;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Avisa por e-mail os primários ativos dos escritórios vinculados a um convênio (spec docs/specs/convenios.md, §6.2):
 * o fim marcado e o fim cancelado ("o convênio continua"). Generaliza o {@see AvisoDeConvenioDaUf}: mesma porta única
 * de envio, tipo transacional, um e-mail por pessoa, dentro da transação de quem chama (falha ao enfileirar desfaz).
 */
final class AvisoDeConvenio
{
    public const ORIGEM = 'convenio';

    public function __construct(
        private readonly PrimariosDosEscritoriosRepository $primarios,
        private readonly CarteiroInterface $carteiro,
        private readonly TranslatorInterface $tradutor,
    ) {
    }

    /**
     * @param list<int> $tenantIds
     *
     * @return list<array{email: string, nome: string}>
     */
    public function destinatarios(array $tenantIds): array
    {
        return $this->primarios->dos(array_values(array_unique($tenantIds)));
    }

    /**
     * Enfileira um aviso por destinatário. `$fim` nulo: o fim foi cancelado. Qualquer falha propaga.
     *
     * @param list<array{email: string, nome: string}> $destinatarios
     *
     * @return int quantos avisos entraram na fila
     */
    public function avisar(Convenio $convenio, ?\DateTimeImmutable $fim, array $destinatarios): int
    {
        foreach ($destinatarios as $d) {
            $this->carteiro->enviar($this->mensagem($convenio, $fim, $d['email'], $d['nome']));
        }

        return \count($destinatarios);
    }

    public function mensagem(Convenio $convenio, ?\DateTimeImmutable $fim, string $email, string $nome): MensagemDeEmail
    {
        $params = ['nome' => $convenio->getNome(), 'data' => $fim?->format('d/m/Y') ?? ''];
        $paragrafos = [$this->t('ola', ['nome' => self::primeiroNome($nome)])];
        if ($fim === null) {
            $paragrafos[] = $this->t('corpo_continua', $params);
        } else {
            $paragrafos[] = $this->t('corpo_fim', $params);
            if ($convenio->getBeneficio()->ehReal()) {
                $paragrafos[] = $this->t('corpo_fim_beneficio', ['beneficio' => $this->beneficio($convenio)]);
            }
            if ($convenio->getMotivo() !== null) {
                $paragrafos[] = $this->t('motivo', ['motivo' => $convenio->getMotivo()]);
            }
        }
        $paragrafos[] = $this->t('fecho');

        return new MensagemDeEmail(
            para: $email,
            assunto: $this->t($fim === null ? 'assunto_continua' : 'assunto_fim', $params),
            corpoHtml: implode('', array_map(static fn (string $p): string => '<p>' . htmlspecialchars($p, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8') . '</p>', $paragrafos)),
            tipo: TipoDeEnvio::Transacional,
            origem: self::ORIGEM,
            nome: $nome,
            titulo: $this->t('titulo', $params),
            nomeDoRemetente: 'BlueJus',
            motivoDoEnvio: $this->t('motivo_do_envio', $params),
        );
    }

    private function beneficio(Convenio $convenio): string
    {
        $valor = $convenio->getBeneficioValor();
        $texto = $convenio->getBeneficio() === BeneficioDoConvenio::DescontoFixo ? number_format($valor / 100, 2, ',', '.') : (string) $valor;

        return $this->t('beneficio.' . $convenio->getBeneficio()->value, ['valor' => $texto]);
    }

    /** @param array<string, string> $params */
    private function t(string $chave, array $params = []): string
    {
        return $this->tradutor->trans('master.convenios_email.' . $chave, $params);
    }

    private static function primeiroNome(string $nome): string
    {
        $partes = preg_split('/\s+/', trim($nome)) ?: [];

        return (string) ($partes[0] ?? '');
    }
}
