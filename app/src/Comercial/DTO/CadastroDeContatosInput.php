<?php

declare(strict_types=1);

namespace App\Comercial\DTO;

use App\Comercial\Exception\CampanhaRecusada;

/**
 * O "Cadastrar e-mail" da Central de envios em modo venda (F3h; desenho 05 VS 21.10.26, `cmLeadAdd` L644): o texto
 * que o Master digitou, com um ou mais e-mails separados por vírgula, espaço ou ponto e vírgula (a mesma regra do dc).
 * Minúsculos, sem repetidos; os inválidos ficam guardados à parte para a resposta contar como barrados.
 */
final class CadastroDeContatosInput
{
    public const MAXIMO = 50;
    public const NENHUM_VALIDO = 'Nenhum e-mail válido.';
    public const LONGO = 'Cadastre até 50 e-mails de cada vez.';
    private const CARACTERES = 20000;
    private const TAMANHO_DO_EMAIL = 180;

    /**
     * @param list<string> $emails    válidos, minúsculos e sem repetidos
     * @param list<string> $invalidos o que foi digitado e não é e-mail
     */
    public function __construct(
        public readonly array $emails,
        public readonly array $invalidos = [],
    ) {
        if ($emails === []) {
            throw new CampanhaRecusada(self::NENHUM_VALIDO);
        }
        if (\count($emails) > self::MAXIMO) {
            throw new CampanhaRecusada(self::LONGO);
        }
    }

    public static function doTexto(mixed $texto): self
    {
        if (!\is_string($texto)) {
            throw new CampanhaRecusada('Pedido inválido.');
        }
        $partes = preg_split('/[\s,;]+/', mb_substr($texto, 0, self::CARACTERES)) ?: [];
        $validos = [];
        $invalidos = [];
        foreach ($partes as $parte) {
            $destinatario = new DestinatarioDaCampanha($parte);
            if ($destinatario->email === '') {
                continue;
            }
            if ($destinatario->valido() && mb_strlen($destinatario->email) <= self::TAMANHO_DO_EMAIL) {
                $validos[$destinatario->email] = true;
            } else {
                $invalidos[] = $destinatario->email;
            }
        }

        return new self(array_keys($validos), $invalidos);
    }
}
