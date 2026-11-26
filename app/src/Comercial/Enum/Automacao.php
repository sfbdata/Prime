<?php

declare(strict_types=1);

namespace App\Comercial\Enum;

/**
 * As automações do desenho 05 (dc L.562-564; spec `comercial-e-emails.md` §2 e §3.4), com o título, a descrição, o
 * gatilho que aparece em "Últimos disparos", as opções de atraso e o padrão do dc. O estado de cada uma mora em
 * `comercial_automacao` (uma linha por chave, criada pela migration com o padrão daqui).
 *
 * O plano de ativação de 7 dias (pedido do Farlei de 26/11/2026) é uma sequência de e-mails para quem acabou de se
 * cadastrar: o dia 1 é a boas-vindas do `val`; os dias 2 a 7 são `at2`...`at7`, cada um com a sua chave de liga/desliga
 * e a sua cópia editável na Central ({@see \App\Comercial\Service\VarreduraDeAutomacoes::mandarAtivacao()}).
 */
enum Automacao: string
{
    case Cad = 'cad';
    case Lemb = 'lemb';
    case Val = 'val';
    case At2 = 'at2';
    case At3 = 'at3';
    case At4 = 'at4';
    case At5 = 'at5';
    case At6 = 'at6';
    case At7 = 'at7';
    case Conv = 'conv';
    case Dica = 'dica';
    case E90 = 'e90';
    case E100 = 'e100';
    case Ped = 'ped';
    /** Opções de espaço extra liberadas pelo Master: aviso fixo, sem chave de liga/desliga e sem linha em `comercial_automacao`. */
    case Lib = 'lib';
    /** Resposta à recusa de um pedido de espaço: aviso fixo, sem chave de liga/desliga. */
    case Resp = 'resp';

    public function titulo(): string
    {
        return match ($this) {
            self::Cad => 'Ao abrir uma conta',
            self::Lemb => 'E-mail não confirmado',
            self::Val => 'Ao confirmar o e-mail',
            self::At2 => 'Ativação, dia 2: primeira pasta',
            self::At3 => 'Ativação, dia 3: primeiro documento',
            self::At4 => 'Ativação, dia 4: organização',
            self::At5 => 'Ativação, dia 5: produtividade',
            self::At6 => 'Ativação, dia 6: oferta de ajuda',
            self::At7 => 'Ativação, dia 7: sugestões',
            self::Conv => 'Convite aceito (secundário)',
            self::Dica => '7 dias depois do cadastro',
            self::E90 => 'Conta chegou a 90% do espaço',
            self::E100 => 'Espaço esgotado (100%)',
            self::Ped => 'Pedido de mais espaço',
            self::Lib => 'Opções de espaço extra liberadas',
            self::Resp => 'Resposta ao pedido de espaço',
        };
    }

    public function descricao(): string
    {
        return match ($this) {
            self::Cad => 'Envia o link e o código para confirmar o e-mail',
            self::Lemb => 'Reenvia a confirmação se não validar',
            self::Val => 'Boas-vindas com os primeiros passos (dia 1 da ativação)',
            self::At2 => 'Orienta a cadastrar um cliente e criar a primeira pasta',
            self::At3 => 'Mostra como anexar uma petição, um contrato ou outro documento',
            self::At4 => 'Mostra como organizar documentos, prazos e dados do processo',
            self::At5 => 'Apresenta as funções que economizam tempo na rotina',
            self::At6 => 'Oferece ajuda só a quem ainda não criou nenhuma pasta',
            self::At7 => 'Agradece, convida a continuar e pede sugestões',
            self::Conv => 'Boas-vindas para quem entrou na equipe',
            self::Dica => 'Dicas para aproveitar o sistema (o plano de ativação já cobre a primeira semana)',
            self::E90 => 'Recomenda aumentar ou solicitar espaço (ao primário)',
            self::E100 => 'Avisa que novos envios estão pausados',
            self::Ped => 'Confirma ao cliente e avisa o Master',
            self::Lib => 'Avisa que as opções de espaço extra foram liberadas',
            self::Resp => 'Responde ao cliente quando o pedido de espaço é recusado',
        };
    }

    /** O rótulo do gatilho em "Últimos disparos" (dc L.565-566). */
    public function gatilho(): string
    {
        return match ($this) {
            self::Cad => 'Conta aberta',
            self::Lemb => 'Lembrete de confirmação',
            self::Val => 'E-mail confirmado',
            self::At2 => 'Ativação, dia 2',
            self::At3 => 'Ativação, dia 3',
            self::At4 => 'Ativação, dia 4',
            self::At5 => 'Ativação, dia 5',
            self::At6 => 'Ativação, dia 6',
            self::At7 => 'Ativação, dia 7',
            self::Conv => 'Convite aceito',
            self::Dica => '7 dias depois',
            self::E90 => 'Espaço em 90%',
            self::E100 => 'Espaço esgotado',
            self::Ped => 'Pedido de espaço',
            self::Lib => 'Opções de espaço liberadas',
            self::Resp => 'Pedido de espaço respondido',
        };
    }

    /** O nome do modelo no botão da linha (dc L.564). */
    public function modelo(): string
    {
        return match ($this) {
            self::Cad, self::Lemb => 'Confirmar e-mail',
            self::Val, self::Conv => 'Boas-vindas',
            self::Dica => 'Primeiros passos (7 dias)',
            self::At2 => 'Ativação: primeira pasta',
            self::At3 => 'Ativação: primeiro documento',
            self::At4 => 'Ativação: organização',
            self::At5 => 'Ativação: produtividade',
            self::At6 => 'Ativação: precisa de ajuda?',
            self::At7 => 'Ativação: sua opinião',
            self::E90 => 'Espaço em 90%',
            self::E100 => 'Espaço esgotado',
            self::Ped => 'Pedido de espaço recebido',
            self::Lib => 'Opções de espaço liberadas',
            self::Resp => 'Resposta ao pedido de espaço',
        };
    }

    /**
     * As opções de atraso do seletor, na ordem do dc; vazio = sem seletor (imediato).
     *
     * @return list<string>
     */
    public function opcoesDeAtraso(): array
    {
        return match ($this) {
            self::Lemb => ['24h', '48h', '72h'],
            self::Dica => ['3d', '7d', '15d'],
            self::E90 => ['80', '85', '90'],
            default => [],
        };
    }

    public function atrasoPadrao(): ?string
    {
        return match ($this) {
            self::Lemb => '24h',
            self::Dica => '7d',
            self::E90 => '90',
            default => null,
        };
    }

    /** As automações do painel: as de aviso fixo (`lib`, `resp`) não têm interruptor nem linha no banco. */
    public function temChave(): bool
    {
        return $this !== self::Lib && $this !== self::Resp;
    }

    /** @return list<self> as que aparecem no painel do Master, na ordem do desenho */
    public static function comChave(): array
    {
        return array_values(array_filter(self::cases(), static fn (self $a): bool => $a->temChave()));
    }

    /** As dicas e o plano de ativação nascem desligados: o Master revê o texto antes de ligar. */
    public function ligadaPorPadrao(): bool
    {
        return $this !== self::Dica && $this->diaDaAtivacao() === null;
    }

    /** O dia do plano de ativação (2 a 7) dos `at*`; nulo nas outras (o dia 1 é a boas-vindas do `val`). */
    public function diaDaAtivacao(): ?int
    {
        return match ($this) {
            self::At2 => 2,
            self::At3 => 3,
            self::At4 => 4,
            self::At5 => 5,
            self::At6 => 6,
            self::At7 => 7,
            default => null,
        };
    }

    /** @return list<self> os dias 2 a 7 do plano de ativação, em ordem */
    public static function daAtivacao(): array
    {
        return [self::At2, self::At3, self::At4, self::At5, self::At6, self::At7];
    }

    /**
     * As que vão como lista (`comercial_dicas`): respeitam o descadastro e medem a leitura. As outras são avisos da
     * própria conta (transacionais).
     */
    public function ehDeLista(): bool
    {
        return $this === self::Dica || $this->diaDaAtivacao() !== null;
    }

    /** O `cad` é obrigatório: sem ele ninguém cria conta (P2). O interruptor aparece ligado e desabilitado. */
    public function obrigatoria(): bool
    {
        return $this === self::Cad;
    }

    public function origem(): string
    {
        return 'automacao:' . $this->value;
    }
}
