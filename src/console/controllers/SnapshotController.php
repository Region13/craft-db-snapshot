<?php
/**
 * DB Snapshot plugin for Craft CMS 3.x
 *
 * Snapshot your database with mysqldump and store it to a an asset volume (S3). Also restore it from that same location. Great for local dev snapshots and nightly backups.
 *
 * @link      https://github.com/jimbojsb
 * @copyright Copyright (c) 2019 Josh butts
 */

namespace jimbojsb\dbsnapshot\console\controllers;

use Aws\S3\S3Client;
use craft\base\VolumeInterface;
use craft\helpers\FileHelper;
use jimbojsb\dbsnapshot\DbSnapshot;

use Craft;
use League\Flysystem\AwsS3v3\AwsS3Adapter;
use League\Flysystem\Filesystem;
use yii\console\Controller;
use yii\console\ExitCode;
use yii\helpers\Console;

/**
 * DB Snapshot Commands
 *
 * @author    Josh butts
 * @package   DbSnapshot
 * @since     1.0.0
 */
class SnapshotController extends Controller
{
    // Public Properties
    // =========================================================================

    /**
     * @var null|string
     */
    public $filename;


    // Public Methods
    // =========================================================================

    /**
     * @param string $actionID
     *
     * @return array|string[]
     */
    public function options($actionID): array
    {
        return [
            'filename',
        ];
    }

    /**
     * create a new snapshot
     */
    public function actionCreate()
    {
        $this->stdout('Creating snapshot ... ');
        $db = Craft::$app->getDb();
        $view = Craft::$app->getView();
        $settings = DbSnapshot::getInstance()->settings;

        $tmpPath = $this->createTempFolder();

        $filename = $view->renderString($settings->getFilename());
        $tmpFile = "$tmpPath/$filename";
        $volumeFile = $filename;

        try {
            $db->backupTo($tmpFile);
            if ($settings->getCompress()) {
                $compressCmd = "/bin/gzip $tmpFile";
                shell_exec($compressCmd);
                $tmpFile = "$tmpFile.gz";
                $volumeFile = "$volumeFile.gz";
            }
            $filesystem = $this->getFilesystem();
            $filesystem->putStream($volumeFile, @fopen($tmpFile, 'r'), []);
        } catch (\Throwable $e) {
            Craft::$app->getErrorHandler()->logException($e);
            $this->stderr('error: ' . $e->getMessage() . PHP_EOL, \craft\helpers\Console::FG_RED);
            unlink($tmpFile);
            return ExitCode::UNSPECIFIED_ERROR;
        }
        unlink($tmpFile);
        $this->stdout("done\n");
        return ExitCode::OK;
    }

    /**
     * List snapshots saved in your bucket.
     */
    public function actionList()
    {
        $this->stdout("Listing snapshots ...");
        $filesystem = $this->getFilesystem()->listContents();
        $files = $this->getFilesystem()->listContents();
        foreach($files as $file){
            $this->stdout($file['basename'] . "\n");
        }
        return ExitCode::OK;
    }

    /**
     * load an existing snapshot. You can pass in a --filename
     */
    public function actionLoad()
    {
        $this->stdout('Loading snapshot ... ');
        $db = Craft::$app->getDb();
        $view = Craft::$app->getView();
        $settings = DbSnapshot::getInstance()->settings;

        $tmpPath = $this->createTempFolder();

        if (!$filename = $this->filename) {
            $filename = $view->renderString($settings->getFilename());
        }

        $tmpFile = "$tmpPath/$filename";
        $volumeFile = $filename;

        try {
            $filesystem = $this->getFilesystem();

            if ($settings->getCompress()) {
                $volumeFile = "$volumeFile.gz";
                $tmpFile = "$tmpFile.gz";
            }

            if ($filesystem->has($volumeFile)) {
                file_put_contents($tmpFile, $filesystem->readStream($volumeFile));
            } else {
                throw new \RuntimeException("Snapshot $volumeFile does not exist");
            }

            if ($settings->getCompress()) {
                $compressCmd = "/bin/gunzip $tmpFile";
                shell_exec($compressCmd);
                $tmpFile = str_replace(".gz", "", $tmpFile);
            }
            $db->restore($tmpFile);

        } catch (\Throwable $e) {
            Craft::$app->getErrorHandler()->logException($e);
            $this->stderr('error: ' . $e->getMessage() . PHP_EOL, \craft\helpers\Console::FG_RED);
            unlink($tmpFile);
            return ExitCode::UNSPECIFIED_ERROR;
        }
        unlink($tmpFile);
        $this->stdout("done\n");
        return ExitCode::OK;
    }

    private function createTempFolder()
    {
        $tmpPath = Craft::$app->path->getStoragePath() . "/db_snapshots";
        if (!file_exists($tmpPath)) {
            mkdir($tmpPath, 0777, true);
        }
        return $tmpPath;
    }

    /**
     * @param \jimbojsb\dbsnapshot\models\Settings $settings
     * @return Filesystem
     */
    private function getFilesystem()
    {
        $settings = DbSnapshot::getInstance()->settings;
        $region = $settings->getRegion();
        $endPoint = $settings->getEndpoint();
        if(empty($region) && !empty($endPoint)){
            //Non-S3 settings. (S3 Compatible like min.io)
            $s3Options = [
                'version' => 'latest',
                'region'  => '',
                'endpoint' => $endPoint,
                'use_path_style_endpoint' => true,
                'credentials' => [
                    'key'    => $settings->getAccessKey(),
                    'secret' => $settings->getSecretKey(),
                ],
            ];
        }else{
            //S3 Options
            $s3Options = [
                'credentials' => [
                    'key' => $settings->getAccessKey(),
                    'secret' => $settings->getSecretKey()
                ],
                'region' => $region,
                'version' => 'latest'
            ];
            if ($settings->getEndpoint()) {
                $s3Options['endpoint'] = $settings->getEndpoint();
            }
        }
        $s3Client = new S3Client($s3Options);
        $s3Adapter = new AwsS3Adapter($s3Client, $settings->getBucket(), $settings->getPath());
        $filesystem = new Filesystem($s3Adapter);
        return $filesystem;
    }
}
