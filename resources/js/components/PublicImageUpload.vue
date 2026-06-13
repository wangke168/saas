<template>
    <div class="public-image-upload">
        <div v-if="mode === 'cover'" class="cover-block">
            <el-upload
                class="cover-uploader"
                :show-file-list="false"
                accept="image/*"
                :http-request="uploadCover"
            >
                <img v-if="coverPreview" :src="coverPreview" class="cover-preview" alt="封面" />
                <el-icon v-else class="cover-icon"><Plus /></el-icon>
            </el-upload>
            <el-button v-if="coverPreview" type="danger" link @click="clearCover">移除封面</el-button>
        </div>

        <div v-else class="gallery-block">
            <div class="gallery-list">
                <div v-for="(url, index) in imagePreviewUrls" :key="imagePaths[index] || index" class="gallery-item">
                    <img :src="url" class="gallery-img" alt="相册" />
                    <span class="gallery-remove" @click="removeGalleryAt(index)">×</span>
                </div>
                <el-upload
                    class="gallery-add"
                    :show-file-list="false"
                    accept="image/*"
                    :http-request="uploadGalleryItem"
                >
                    <el-icon><Plus /></el-icon>
                </el-upload>
            </div>
        </div>
        <div class="hint">{{ hint }}</div>
    </div>
</template>

<script setup>
import { computed } from 'vue';
import { Plus } from '@element-plus/icons-vue';
import { ElMessage } from 'element-plus';
import axios from '../utils/axios';

const props = defineProps({
    mode: {
        type: String,
        default: 'cover',
    },
    coverPath: {
        type: String,
        default: '',
    },
    coverPreviewUrl: {
        type: String,
        default: '',
    },
    imagePaths: {
        type: Array,
        default: () => [],
    },
    imagePreviewUrls: {
        type: Array,
        default: () => [],
    },
    directory: {
        type: String,
        default: 'hotel-media',
    },
    hint: {
        type: String,
        default: '支持 jpg/png/webp，单张不超过 5MB',
    },
});

const emit = defineEmits(['update:coverPath', 'update:coverPreviewUrl', 'update:imagePaths', 'update:imagePreviewUrls']);

const coverPreview = computed(() => props.coverPreviewUrl || '');

const uploadImage = async (file) => {
    const formData = new FormData();
    formData.append('file', file);
    formData.append('directory', props.directory);
    const response = await axios.post('/uploads/images', formData, {
        headers: { 'Content-Type': 'multipart/form-data' },
    });
    return response.data.data;
};

const uploadCover = async ({ file, onSuccess, onError }) => {
    try {
        const data = await uploadImage(file);
        emit('update:coverPath', data.path);
        emit('update:coverPreviewUrl', data.url);
        onSuccess(data);
    } catch (error) {
        ElMessage.error(error.response?.data?.message || '封面上传失败');
        onError(error);
    }
};

const clearCover = () => {
    emit('update:coverPath', '');
    emit('update:coverPreviewUrl', '');
};

const uploadGalleryItem = async ({ file, onSuccess, onError }) => {
    try {
        const data = await uploadImage(file);
        emit('update:imagePaths', [...props.imagePaths, data.path]);
        emit('update:imagePreviewUrls', [...props.imagePreviewUrls, data.url]);
        onSuccess(data);
    } catch (error) {
        ElMessage.error(error.response?.data?.message || '图片上传失败');
        onError(error);
    }
};

const removeGalleryAt = (index) => {
    const paths = [...props.imagePaths];
    const urls = [...props.imagePreviewUrls];
    paths.splice(index, 1);
    urls.splice(index, 1);
    emit('update:imagePaths', paths);
    emit('update:imagePreviewUrls', urls);
};
</script>

<style scoped>
.cover-uploader {
    border: 1px dashed #d9d9d9;
    border-radius: 8px;
    cursor: pointer;
    width: 148px;
    height: 148px;
    display: flex;
    align-items: center;
    justify-content: center;
    overflow: hidden;
}

.cover-preview {
    width: 148px;
    height: 148px;
    object-fit: cover;
}

.cover-icon {
    font-size: 28px;
    color: #8c939d;
}

.cover-block {
    display: flex;
    align-items: flex-end;
    gap: 12px;
}

.gallery-list {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
}

.gallery-item,
.gallery-add {
    width: 100px;
    height: 100px;
    border-radius: 8px;
    position: relative;
}

.gallery-img {
    width: 100px;
    height: 100px;
    object-fit: cover;
    border-radius: 8px;
}

.gallery-remove {
    position: absolute;
    top: 2px;
    right: 6px;
    color: #fff;
    background: rgba(0, 0, 0, 0.5);
    border-radius: 50%;
    width: 20px;
    height: 20px;
    line-height: 18px;
    text-align: center;
    cursor: pointer;
    font-size: 16px;
}

.gallery-add {
    border: 1px dashed #d9d9d9;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
}

.hint {
    margin-top: 8px;
    font-size: 12px;
    color: #909399;
}
</style>
