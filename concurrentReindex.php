#!/usr/bin/php
<?php
  /**
   * Concurrent database reindexing
   * Reindex the database table by table concurrently, ignoring primary keys or unique constraints
   * This has only been tested against 8.2.4 - Your milage may vary
   * @author Gavin M. Roy <gmr@myyearbook.com>
   * @since 2008-05-09
   * @copyright 2008 Insider Guides, Inc.
   * @license BSD
   */

  // Concurrent Reindex Code
  function concurrentReindex($table)
  {
    global $io, $pdo;
    
    // Get the schema for the original index name
    $temp = explode('.', $table);
    $schema = $temp[0];

    // Grab the indexes
    $query = $pdo->prepare("SELECT * FROM pg_indexes WHERE schemaname || '.' || tablename = :table AND indexdef NOT LIKE '%UNIQUE%' ORDER BY indexname OFFSET $io;");
    $query->bindParam(':table', $table);
    $query->execute();
    $indexes = $query->fetchAll(PDO::FETCH_OBJ);

    // Loop through each index and process it.
    foreach ( $indexes AS $index )
    {
      // Temporary index name
      $tempname = 'prc_temp_' . $index->indexname;

      // Concurrently reindex
      // use preg_replace for limiting on edge case index names that match column names
      $sql = preg_replace('/' . $index->indexname . '/', $tempname, $index->indexdef, 1);
      $sql = preg_replace('/create index/i', 'CREATE INDEX CONCURRENTLY', $sql);
      echo "  " . $sql . "\n";
      $pdo->query($sql);

      // If there is any error abort
      $error = $pdo->errorInfo();
      if ( $error[0] != '0000' ) 
      {
        $sql = 'DROP INDEX "' . $tempname . '"';
        echo "  " . $sql . "\n";
        $pdo->query($sql);
        die(print_r($error));
      }

      // Remove the old index
      $sql = 'DROP INDEX ' . $schema . '."' . $index->indexname . '"';
      echo "  " . $sql . "\n";
      $pdo->query($sql);

      // If there is any error abort
      $error = $pdo->errorInfo();
      if ( $error[0] != '0000' ) die(print_r($error));
      
      // Rename the temp index name to the proper index name
      $sql = 'ALTER INDEX ' . $schema . '."' . $tempname . '" RENAME TO "' . $index->indexname . '"';
      echo "  " . $sql . "\n";
      $pdo->query($sql);

      // If there is any error abort
      $error = $pdo->errorInfo();
      if ( $error[0] != '0000' ) die(print_r($error));
      
      // Analyze the table so the new index is used right away
      $sql = 'ANALYZE ' . $table;
      echo "  " . $sql . "\n";
      $pdo->query($sql);

      // If there is any error abort
      $error = $pdo->errorInfo();
      if ( $error[0] != '0000' ) die(print_r($error));
    }

    if ( !Count($indexes) )
      echo "  No non-unique indexes to reindex.\n";
  }
  
  // Usage example
  $usage = <<<USAGE
Usage: concurrentReindex.php parameters

Parameters:

  -host       Specify the database host to connect to - required
  -port       Specify the port to connect to
  -dbname     Specify the database name to connect to - required
  -user       Specify the database user to connect as - required
  -password   Specify the database user password
  -start      Start offset at this table number in processing order
  -table      Reindex a specific table or pattern
  -io	      Index offset if doing only one table

Example:

  ./concurrentReindex.php -host localhost -user postgres -dbname test

USAGE;

  // Default start offset
  $offset = 0;
  $io = 0;

  // Get parameters
  for ( $y = 1; $y < $argc; $y++ )
  {
    $command = $argv[$y];
    $value = $argv[$y+1];
    $y++;
    switch ( $command )
    {
      case '-host':
        $host = $value;
        break;
      case '-dbname':
        $dbname = $value;
        break;
      case '-port':
        $port = $value;
        break;
      case '-user':
        $user = $value;
        break;
      case '-password':
        $password = $value;
        break;
      case '-start':
        $offset = $value;
        break;
      case '-table':
        $table = $value;
        break;
      case '-io':
        $io = $value;
        break;
      default:
        die('Unknown parameter: ' . $command . "\n");
    }
  }

  if ( !isset($host) || !isset($dbname) || !isset($user) )
  { 
    die("\nError: Required parameters not set.\n\n" . $usage . "\n");
  }

  // Start time
  $start = time();

  // Build the connect string
  $connect = 'pgsql:';
  $connect .= 'host=' . $host . ';';
  if ( isset($port) )
    $connect .= 'port=' . $port . ';';
  $connect .= 'user=' . $user . ';';
  if ( isset($password) )
    $connect .= 'password=' . $password . ';';
  $connect .= 'dbname=' . $dbname . ';';
  
  // Connect to the database and get all of the tables that have indexes, prefaced with schemaname
  $pdo = new PDO($connect);

  if ( isset($table) && !strstr($table, '%') )
  {
    echo "Reindexing $table concurrently:\n\n";

    // Show current size before index
    $query = $pdo->prepare('SELECT pg_size_pretty(pg_total_relation_size(:table));');
    $query->bindParam(':table', $table);
    $query->execute();
    echo " Current size: " . $query->fetchColumn(0) . "\n";

    // Reindex
    concurrentReindex($table);

    // Show post reindex size
    $query = $pdo->prepare('SELECT pg_size_pretty(pg_total_relation_size(:table));');
    $query->bindParam(':table', $table);
    $query->execute();
    echo " Post reindex size: " . $query->fetchColumn(0) . "\n\n";
    die();
  }

  if ( isset($table) && strstr($table, '%') )
  {
    $sql = "SELECT schemaname || '.' || tablename AS tablename FROM pg_tables WHERE hasindexes = 't' AND schemaname <> 'pg_catalog' AND schemaname || '.' || tablename LIKE '" . $table . "' ORDER BY schemaname, tablename;";
  } else {
    $sql = "SELECT schemaname || '.' || tablename AS tablename FROM pg_tables WHERE hasindexes = 't' AND schemaname <> 'pg_catalog' ORDER BY tablename;";
  }
  echo $sql;
  $query = $pdo->query($sql);
  $tables = $query->fetchAll(PDO::FETCH_ASSOC);
  for ( $y = $offset; $y < Count($tables); $y++ )
  {
    echo "Reindexing table # $y of " . Count($tables) . ": " . $tables[$y]['tablename'] . "\n";

    // Show current size before index
    $query = $pdo->prepare('SELECT pg_size_pretty(pg_total_relation_size(:table));');
    $query->bindParam(':table', $tables[$y]['tablename']);
    $query->execute();
    echo " Current size: " . $query->fetchColumn(0) . "\n";

    // Reindex
    concurrentReindex($tables[$y]['tablename']);

    // Show post reindex size
    $query = $pdo->prepare('SELECT pg_size_pretty(pg_total_relation_size(:table));');
    $query->bindParam(':table', $tables[$y]['tablename']);
    $query->execute();
    echo " Post reindex size: " . $query->fetchColumn(0) . "\n\n";
  }

  // End of process information
  echo "\n\nProcess completed with " . count($tables) . " tables reindexed in " . ( time() - $start ) . " seconds.\n\n";
?>
