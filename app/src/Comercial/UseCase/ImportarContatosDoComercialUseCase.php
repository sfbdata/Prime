<?php

declare(strict_types=1);

namespace App\Comercial\UseCase;

use App\Comercial\Campanha\ListaInformada;
use App\Comercial\DTO\ImportacaoDeContatosOutput;
use App\Comercial\Entity\ContatoComercial;
use App\Comercial\Exception\CampanhaRecusada;
use App\Comercial\Exception\ContatoRecusado;
use App\Comercial\Repository\ContatoComercialRepository;
use App\Comercial\Service\AuditoriaDaBaseDeContatos;
use App\Entity\Auth\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Importa o CSV (colunas `email[,nome]`) para a Base de contatos, com a leitura e os limites da lista informada das
 * campanhas (5.000 linhas, 1 MB). Endereço inválido é contado e ignorado. Repetido não duplica: o contato que já
 * existe conta como "atualizado" e só troca o nome quando a linha traz um. Repetido dentro do próprio arquivo conta
 * uma vez (vale o último nome preenchido). A origem do contato novo é `importacao`, com o nome do arquivo.
 */
final class ImportarContatosDoComercialUseCase
{
    public function __construct(
        private readonly ContatoComercialRepository $contatos,
        private readonly EntityManagerInterface $em,
        private readonly AuditoriaDaBaseDeContatos $auditoria,
    ) {
    }

    /** @throws ContatoRecusado arquivo grande demais ou sem nenhuma linha com e-mail */
    public function executar(string $csv, ?string $arquivo, User $master): ImportacaoDeContatosOutput
    {
        [$validos, $invalidos] = $this->lerValidos($csv);

        $existentes = $this->contatos->porEmails(array_map('strval', array_keys($validos)));
        $agora = new \DateTimeImmutable();
        $novos = 0;
        foreach ($validos as $email => $nome) {
            $email = (string) $email;
            if (isset($existentes[$email])) {
                if ($nome !== '') {
                    $existentes[$email]->renomear($nome, $agora);
                }
                continue;
            }
            $this->contatos->adicionar(new ContatoComercial($email, $nome, ContatoComercial::ORIGEM_IMPORTACAO, $arquivo, $master, $agora));
            ++$novos;
        }
        $this->em->flush();
        $resultado = new ImportacaoDeContatosOutput($novos, \count($existentes), $invalidos);
        $this->auditoria->registrar(AuditoriaDaBaseDeContatos::IMPORTADO, null, ['arquivo' => $arquivo] + $resultado->paraJson(), $master);

        return $resultado;
    }

    /**
     * @return array{0: array<string, string>, 1: int} os endereços válidos (e-mail => nome; repetido vale o último nome
     *                                                  preenchido) e quantas linhas tinham endereço inválido
     *
     * @throws ContatoRecusado
     */
    private function lerValidos(string $csv): array
    {
        try {
            $linhas = ListaInformada::doCsv($csv);
        } catch (CampanhaRecusada $e) {
            throw new ContatoRecusado($e->getMessage(), 0, $e);
        }
        if ($linhas === []) {
            throw new ContatoRecusado('O arquivo não tem nenhuma linha com e-mail.');
        }
        $invalidos = 0;
        $validos = [];
        foreach ($linhas as $linha) {
            if (!$linha->valido() || mb_strlen($linha->email) > ContatoComercial::TAMANHO_DO_EMAIL) {
                ++$invalidos;
                continue;
            }
            $validos[$linha->email] = $linha->nome !== '' ? $linha->nome : ($validos[$linha->email] ?? '');
        }

        return [$validos, $invalidos];
    }
}
