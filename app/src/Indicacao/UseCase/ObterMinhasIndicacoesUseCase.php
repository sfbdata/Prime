<?php

declare(strict_types=1);

namespace App\Indicacao\UseCase;

use App\Entity\Auth\User;
use App\Indicacao\DTO\IndicacaoDoUsuarioOutput;
use App\Indicacao\DTO\MinhasIndicacoesOutput;
use App\Indicacao\Repository\IndicacaoRepository;
use App\Indicacao\Service\CodigoDeIndicacao;
use App\Indicacao\Service\PortaoDaIndicacao;
use App\Indicacao\Service\RegistroDeIndicacao;

/**
 * A janela "Indicar o BlueJus" (bj-contas.js L98-133), de quem está logado (com ou sem escritório): o nome que o
 * convite leva, o link curto (o código nasce aqui no primeiro acesso), a mensagem sugerida do WhatsApp, o que falta
 * no cadastro ou, com ele completo, a trava vigente, e "SUAS INDICAÇÕES" (o total e as 8 mais recentes).
 */
final class ObterMinhasIndicacoesUseCase
{
    public const ULTIMAS = 8;

    public function __construct(
        private readonly IndicacaoRepository $indicacoes,
        private readonly PortaoDaIndicacao $portao,
        private readonly CodigoDeIndicacao $codigos,
    ) {
    }

    public function executar(User $user): MinhasIndicacoesOutput
    {
        $userId = (int) $user->getId();
        $nome = trim((string) $user->getFullName());
        $link = $this->codigos->linkCurtoDe($user);
        $pendencias = $this->portao->pendenciasDoCadastro($user);

        return new MinhasIndicacoesOutput(
            nome: RegistroDeIndicacao::nomeDe($user),
            linkCurto: $link,
            mensagem: self::mensagemSugerida($nome, $link),
            pendencias: $pendencias,
            textoDasPendencias: PortaoDaIndicacao::textoDasPendencias($pendencias),
            trava: $pendencias === [] ? $this->portao->motivoDaTrava($user) : null,
            total: $this->indicacoes->contarDoUsuario($userId),
            ultimas: $this->ultimas($userId),
        );
    }

    /** O texto editável da caixa (bj-contas.js L103); vai só no WhatsApp. */
    public static function mensagemSugerida(string $nome, string $link): string
    {
        return 'Olá! Aqui é ' . ($nome !== '' ? $nome : 'um colega') . '. Uso o BlueJus no meu escritório para controlar prazos, '
            . 'processos e documentos em um só lugar, e é grátis para começar. Crie a sua conta pelo meu convite: ' . $link;
    }

    /** @return list<IndicacaoDoUsuarioOutput> */
    private function ultimas(int $userId): array
    {
        return array_map(static fn (array $l): IndicacaoDoUsuarioOutput => new IndicacaoDoUsuarioOutput(
            nome: $l['nome'],
            email: $l['email'],
            canal: $l['canal'],
            em: new \DateTimeImmutable($l['criada_em']),
            contaCriada: $l['conta_criada'],
        ), $this->indicacoes->ultimasDoUsuario($userId, self::ULTIMAS));
    }
}
