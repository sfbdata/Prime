<?php

declare(strict_types=1);

namespace App\Tests\Arquitetura;

use App\Shared\Scheduler\AgendaDasCadeias;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * As quatro varreduras periódicas (push, Google Agenda, automações e expansor do Comercial) vêm do schedule `cadeias`
 * (spec docs/specs/scheduler-tarefas-agendadas.md, "Cadeias no Scheduler"). Quem o consome em produção é o **worker**,
 * que sobe em todo deploy; o serviço `scheduler` pode ficar desligado pela trava do crontab
 * (`SEM_AGENDADOR=1`, `--scale scheduler=0`) e não pode levar as varreduras junto.
 *
 * O deploy em si é provado em scripts/testar-deploy-guardas.sh e scripts/testar-deploy-automatico.sh; aqui, só o
 * docker-compose.prod.yml.
 */
#[CoversNothing]
final class ConsumidorDasCadeiasArquiteturaTest extends TestCase
{
    #[TestDox('O worker de produção consome o scheduler_cadeias antes das filas, sempre ligado')]
    public function testWorkerConsomeAsCadeias(): void
    {
        $worker = $this->servicos()['worker'];

        self::assertSame(
            ['messenger:consume', 'scheduler_' . AgendaDasCadeias::NOME, 'comercial', 'async'],
            \array_slice($worker['command'], 4, 4),
            'command do worker: bin/console messenger:consume scheduler_cadeias comercial async',
        );
        self::assertSame('unless-stopped', $worker['restart']);
        self::assertArrayNotHasKey('profiles', $worker, 'worker em profile não sobe no `up -d` do deploy');
        self::assertArrayNotHasKey('deploy', $worker, 'réplicas/escala do worker não se mexem no compose');
    }

    #[TestDox('O agendador consome só o scheduler_default, e ninguém além do worker consome o scheduler_cadeias')]
    public function testSoOWorkerConsomeAsCadeias(): void
    {
        $consumidores = [];
        foreach ($this->servicos() as $nome => $servico) {
            if (\in_array('scheduler_' . AgendaDasCadeias::NOME, (array) ($servico['command'] ?? []), true)) {
                $consumidores[] = $nome;
            }
        }

        self::assertSame(['worker'], $consumidores);
        self::assertSame(['messenger:consume', 'scheduler_default', '--time-limit=3600'], \array_slice($this->servicos()['scheduler']['command'], 4, 3));
    }

    /** @return array<string, array<string, mixed>> */
    private function servicos(): array
    {
        $arquivo = \dirname(__DIR__, 3) . '/docker-compose.prod.yml';
        self::assertFileExists($arquivo);
        $compose = Yaml::parseFile($arquivo);
        self::assertIsArray($compose);

        return $compose['services'];
    }
}
