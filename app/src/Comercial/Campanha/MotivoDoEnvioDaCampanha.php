<?php

declare(strict_types=1);

namespace App\Comercial\Campanha;

use App\Comercial\Entity\ContatoComercial;
use App\Comercial\Repository\ContatoComercialRepository;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * A frase "por que você recebeu" do rodapé da campanha, conforme a origem do destinatário (C1, LGPD). Antes, todo
 * e-mail de campanha dizia "se cadastrou no BlueJus ou autorizou o envio de novidades", o que não vale para quem está
 * numa lista importada. Sem coluna nova: a origem sai do público da campanha, da lista pela qual o destinatário entrou
 * ({@see \App\Comercial\DTO\DestinatarioDaCampanha::$lista}) e, nos Contatos salvos, da origem gravada em
 * `comercial_contato` ({@see ContatoComercial}).
 *
 *   - `prim`, `sec`, `todos`: tem conta no BlueJus;
 *   - `leads`: confirmou o endereço na lista (`comercial_campanhas` ou, no modelo `vagas`, `vagas_alerta`);
 *   - `csv`, `indiv`: tem conta ou confirmou o endereço (o B1 de {@see PoliticaDeDestinatarios} só deixa esses);
 *   - `contatos`: a lista importada ou o cadastro feito pela equipe; sem a origem (o contato saiu da base, o teste,
 *     a prévia sem exemplo), a frase geral da base de contatos.
 * Público desconhecido (o `teste` gravado, um pedido sem público) fica com a frase de lista de antes.
 * Só a frase muda: o link de descadastro e o List-Unsubscribe continuam os da lista ({@see \App\Comercial\Service\MontadorDeEmail}).
 */
final class MotivoDoEnvioDaCampanha
{
    private const PREFIXO = 'emails.comercial.layout.';
    private const CADASTRADOS = ['prim', 'sec', 'todos'];
    private const INFORMADOS = ['csv', 'indiv'];

    public function __construct(
        private readonly TranslatorInterface $tradutor,
        private readonly ContatoComercialRepository $contatos,
    ) {
    }

    /**
     * A origem de cada endereço que está nos Contatos salvos (uma consulta para o lote inteiro).
     *
     * @param list<string> $emails
     *
     * @return array<string, string> e-mail (minúsculo) => origem
     */
    public function origensDosContatos(array $emails): array
    {
        return $emails === [] ? [] : $this->contatos->origensPorEmail($emails);
    }

    /** A frase de um destinatário só (a prévia): nos Contatos salvos, busca a origem dele. */
    public function doDestinatario(string $publico, string $lista, string $email): string
    {
        $origem = $publico === PublicosDaCampanha::CONTATOS_SALVOS
            ? $this->origensDosContatos([$email])[mb_strtolower(trim($email))] ?? null
            : null;

        return $this->frase($publico, $lista, $origem);
    }

    /** `$origemDoContato` só vale no público `contatos` (`importacao` ou `manual`; null = não se sabe). */
    public function frase(string $publico, string $lista = ComposicaoDaCampanha::LISTA, ?string $origemDoContato = null): string
    {
        return $this->tradutor->trans(self::PREFIXO . self::chave($publico, $lista, $origemDoContato));
    }

    private static function chave(string $publico, string $lista, ?string $origemDoContato): string
    {
        if (\in_array($publico, self::CADASTRADOS, true)) {
            return 'motivo_campanha.cadastrados';
        }
        if (\in_array($publico, self::INFORMADOS, true)) {
            return 'motivo_campanha.lista_informada';
        }

        return match ($publico) {
            'leads' => $lista === PublicosDaCampanha::LISTA_DE_VAGAS ? 'motivo_campanha.alertas_de_vagas' : 'motivo_campanha.base_de_contatos',
            PublicosDaCampanha::CONTATOS_SALVOS => match ($origemDoContato) {
                ContatoComercial::ORIGEM_IMPORTACAO => 'motivo_campanha.contato_importado',
                ContatoComercial::ORIGEM_MANUAL => 'motivo_campanha.contato_incluido',
                default => 'motivo_campanha.contatos_salvos',
            },
            default => 'motivo_lista',
        };
    }
}
